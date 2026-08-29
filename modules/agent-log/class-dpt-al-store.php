<?php
/**
 * Agent Log module - the table.
 *
 * Every query is built here and nowhere else, and the thing that runs them is
 * injected rather than reached for. $wpdb satisfies the interface; so does a
 * recorder in a test. A storage layer that cannot be exercised is a storage
 * layer whose bugs ship, and this one is written by automated clients whose
 * mistakes nobody watches in real time.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Store {

	const DEFAULT_PER_PAGE = 20;
	const MAX_PER_PAGE     = 100;
	const SCHEMA_VERSION   = '1';

	/**
	 * How many bytes of encoded field list a row may carry.
	 *
	 * The column is TEXT, which holds 65,535 bytes - bytes, not characters,
	 * and a Hebrew or Japanese meta key costs six bytes per character once
	 * wp_json_encode() escapes it to \uXXXX. One bulk import touching a few
	 * hundred long meta keys on a single object is enough to pass that, and
	 * under MySQL strict mode an overlong value errors the whole INSERT -
	 * losing not just the field list but the row that says the object changed
	 * at all. So the list is bounded here, well short of the boundary rather
	 * than sitting on it, since nothing else about this row is worth risking
	 * for the last few hundred bytes of a list nobody reads to the end.
	 */
	const MAX_FIELDS_BYTES = 60000;

	/** @var object|null */
	private static $writer = null;

	/**
	 * The channels a row may carry. Closed on purpose: a value from a query
	 * string that is not one of these contributes no clause at all, so a
	 * caller cannot widen the query by inventing a channel.
	 */
	private static $channels = array( 'rest', 'cron', 'cli', 'xmlrpc' );

	private static $object_types = array( 'post', 'term', 'attachment', 'user', 'plugin', 'theme', 'option' );

	/**
	 * @param object $writer Anything with $prefix, insert(), query(),
	 *                       get_results(), get_var() and prepare().
	 * @return void
	 */
	public static function set_writer( $writer ) {
		self::$writer = $writer;
	}

	/**
	 * @return object
	 */
	public static function writer() {
		if ( null === self::$writer ) {
			global $wpdb;
			self::$writer = $wpdb;
		}
		return self::$writer;
	}

	/**
	 * @return string
	 */
	public static function table() {
		return self::writer()->prefix . 'dpt_agent_log';
	}

	/**
	 * The columns, their defaults, their printf formats and the width of the
	 * column they land in, in one place so insert() cannot pass a value
	 * without a format - which is what makes a value reach the database
	 * unescaped - or longer than the column takes, which under MySQL strict
	 * mode (the default on 5.7 and 8.0) errors the whole INSERT and loses the
	 * row. Plugin basenames routinely exceed 60 characters and post titles
	 * 191. 'fields' carries 0 here because it is TEXT and is bounded in bytes
	 * by encode_fields() rather than in characters by truncate().
	 *
	 * @return array
	 */
	private static function columns() {
		return array(
			'logged_at'      => array( '', '%s', 19 ),
			'channel'        => array( '', '%s', 20 ),
			'app'            => array( '', '%s', 100 ),
			'user_id'        => array( 0, '%d', 0 ),
			'action'         => array( '', '%s', 40 ),
			'object_type'    => array( '', '%s', 40 ),
			'object_subtype' => array( '', '%s', 60 ),
			'object_id'      => array( 0, '%d', 0 ),
			'object_name'    => array( '', '%s', 191 ),
			'fields'         => array( array(), '%s', 0 ),
		);
	}

	/**
	 * Cut a string to a column's width without splitting a character.
	 *
	 * substr() on multi-byte text cuts mid-character and writes invalid UTF-8,
	 * which is a worse outcome than the overlong value it was fixing. So there
	 * is no substr() fallback here: inside WordPress mb_substr() always
	 * exists. Core polyfills it in wp-includes/compat.php, which at line 256
	 * declares it when the mbstring extension is missing and delegates to the
	 * UTF-8-safe _mb_substr().
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum characters, 0 or less for no bound.
	 * @return string
	 */
	private static function truncate( $value, $length ) {
		if ( (int) $length < 1 ) {
			return $value;
		}
		return mb_substr( $value, 0, (int) $length );
	}

	/**
	 * Encode a field list small enough to reach the column.
	 *
	 * The bound is on the encoded string in bytes, because bytes are what the
	 * column counts: mb_strlen() here would be the bug rather than the fix,
	 * and so would measuring the names before wp_json_encode() escaped them.
	 * Names are measured one at a time - the encoding of a JSON array of
	 * strings is exactly the two brackets, each element's own encoding, and a
	 * comma between each pair - and the leading names that fit are kept.
	 *
	 * Dropping the tail is the point. The endpoint and the screen both
	 * json_decode() this column and must get an array back, so a string cut
	 * mid-name would be worse than useless; but a row naming four hundred of
	 * five hundred changed fields is worth far more than no row at all, which
	 * is the alternative once MySQL refuses the INSERT.
	 *
	 * @param array $fields Field names.
	 * @return string Valid JSON for an array, at most MAX_FIELDS_BYTES bytes.
	 */
	private static function encode_fields( $fields ) {
		$names = array_values( array_map( 'strval', $fields ) );
		$json  = wp_json_encode( $names );

		if ( is_string( $json ) && strlen( $json ) <= self::MAX_FIELDS_BYTES ) {
			return $json;
		}

		// '[]' and the comma each element after the first needs.
		$budget = self::MAX_FIELDS_BYTES - 2;
		$kept   = array();

		foreach ( $names as $name ) {
			$encoded = wp_json_encode( $name );
			if ( ! is_string( $encoded ) ) {
				// wp_json_encode() fails on malformed UTF-8. One unwritable
				// name is not a reason to lose the names around it.
				continue;
			}
			$cost = strlen( $encoded ) + ( empty( $kept ) ? 0 : 1 );
			if ( $cost > $budget ) {
				break;
			}
			$budget -= $cost;
			$kept[]  = $name;
		}

		$json = wp_json_encode( $kept );

		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * Write one row.
	 *
	 * @param array $row Partial row; anything missing takes its default.
	 * @return bool
	 */
	public static function insert( $row ) {
		$data    = array();
		$formats = array();

		foreach ( self::columns() as $name => $spec ) {
			list( $default, $format, $max ) = $spec;
			$value                          = array_key_exists( $name, $row ) ? $row[ $name ] : $default;

			if ( 'fields' === $name ) {
				$value = self::encode_fields( (array) $value );
			} elseif ( '%d' === $format ) {
				$value = (int) $value;
			} else {
				$value = is_scalar( $value ) ? (string) $value : '';
				$value = self::truncate( $value, $max );
			}

			$data[ $name ] = $value;
			$formats[]     = $format;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this module's own table.
		return (bool) self::writer()->insert( self::table(), $data, $formats );
	}

	/**
	 * Turn request arguments into a WHERE clause, its parameters and a window.
	 *
	 * Nothing is interpolated. Every value that survives becomes a
	 * placeholder and a matching entry in 'params', so the caller cannot
	 * build a query this class did not intend.
	 *
	 * @param array $args Raw arguments.
	 * @return array
	 */
	public static function query_args( $args ) {
		$clauses = array();
		$params  = array();

		if ( isset( $args['channel'] ) && in_array( $args['channel'], self::$channels, true ) ) {
			$clauses[] = 'channel = %s';
			$params[]  = $args['channel'];
		}
		if ( isset( $args['object_type'] ) && in_array( $args['object_type'], self::$object_types, true ) ) {
			$clauses[] = 'object_type = %s';
			$params[]  = $args['object_type'];
		}
		if ( isset( $args['object_id'] ) && (int) $args['object_id'] > 0 ) {
			$clauses[] = 'object_id = %d';
			$params[]  = (int) $args['object_id'];
		}
		if ( isset( $args['app'] ) && is_scalar( $args['app'] ) && '' !== (string) $args['app'] ) {
			$clauses[] = 'app = %s';
			$params[]  = (string) $args['app'];
		}
		$range = array(
			'after'  => '>=',
			'before' => '<=',
		);
		foreach ( $range as $key => $operator ) {
			if ( ! isset( $args[ $key ] ) || ! is_scalar( $args[ $key ] ) ) {
				continue;
			}
			$stamp = strtotime( (string) $args[ $key ] . ' UTC' );
			if ( ! $stamp ) {
				continue;
			}
			$clauses[] = 'logged_at ' . $operator . ' %s';
			$params[]  = gmdate( 'Y-m-d H:i:s', $stamp );
		}

		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : self::DEFAULT_PER_PAGE;
		if ( $per_page < 1 ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}
		$per_page = min( $per_page, self::MAX_PER_PAGE );

		$page = isset( $args['page'] ) ? (int) $args['page'] : 1;
		if ( $page < 1 ) {
			$page = 1;
		}

		return array(
			'where'  => $clauses ? implode( ' AND ', $clauses ) : '',
			'params' => $params,
			'limit'  => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
		);
	}

	/**
	 * What pruning would do, without doing it.
	 *
	 * Two bounds, and each is disabled by a value of zero or less - the same
	 * code path for "the operator turned this off" and "the setting is
	 * nonsense", on purpose. Both disabled returns no work rather than a
	 * DELETE with no bound, which would empty the table.
	 *
	 * @param int $max_age_days Days to keep, 0 or less to disable.
	 * @param int $max_rows     Rows to keep, 0 or less to disable.
	 * @param int $now          Current timestamp.
	 * @return array
	 */
	public static function prune_plan( $max_age_days, $max_rows, $now ) {
		$plan = array();

		if ( (int) $max_age_days > 0 ) {
			$plan[] = array(
				'kind'   => 'age',
				'cutoff' => gmdate( 'Y-m-d H:i:s', (int) $now - ( (int) $max_age_days * DAY_IN_SECONDS ) ),
			);
		}
		if ( (int) $max_rows > 0 ) {
			$plan[] = array(
				'kind' => 'rows',
				'keep' => (int) $max_rows,
			);
		}

		return $plan;
	}

	/**
	 * Carry out what prune_plan() described.
	 *
	 * @param int $max_age_days Days to keep, 0 or less to disable.
	 * @param int $max_rows     Rows to keep, 0 or less to disable.
	 * @param int $now          Current timestamp.
	 * @return void
	 */
	public static function prune( $max_age_days, $max_rows, $now ) {
		$writer = self::writer();
		$table  = self::table();

		foreach ( self::prune_plan( $max_age_days, $max_rows, $now ) as $step ) {
			if ( 'age' === $step['kind'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, name from the writer prefix; the value is prepared.
				$writer->query( $writer->prepare( "DELETE FROM {$table} WHERE logged_at < %s", $step['cutoff'] ) );
				continue;
			}
			// Keep the newest $keep rows: find the id at that depth and drop
			// everything below it. One comparison against an indexed column,
			// rather than a subquery over the whole table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; the value is prepared.
			$floor = $writer->get_var( $writer->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", (int) $step['keep'] ) );
			if ( $floor ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; the value is prepared.
				$writer->query( $writer->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $floor ) );
			}
		}
	}

	/**
	 * @param array $args Request arguments.
	 * @return array
	 */
	public static function query( $args ) {
		$parts  = self::query_args( $args );
		$writer = self::writer();
		$table  = self::table();
		$sql    = "SELECT * FROM {$table}";
		if ( '' !== $parts['where'] ) {
			$sql .= ' WHERE ' . $parts['where'];
		}
		$sql   .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params = array_merge( $parts['params'], array( $parts['limit'], $parts['offset'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; every value is a placeholder filled by prepare().
		$rows = $writer->get_results( $writer->prepare( $sql, ...$params ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array $args Request arguments.
	 * @return int
	 */
	public static function count( $args ) {
		$parts  = self::query_args( $args );
		$writer = self::writer();
		$table  = self::table();
		$sql    = "SELECT COUNT(*) FROM {$table}";
		if ( '' === $parts['where'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- own table, no user input in the statement.
			return (int) $writer->get_var( $sql );
		}
		$sql .= ' WHERE ' . $parts['where'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; every value is a placeholder filled by prepare().
		return (int) $writer->get_var( $writer->prepare( $sql, ...$parts['params'] ) );
	}

	/**
	 * Create or upgrade the table.
	 *
	 * Called from the module's init(), not from the plugin's activation hook:
	 * a module that has never been switched on should not leave a table
	 * behind on every site the plugin is installed on. Guarded by a stored
	 * version so dbDelta does not run on every page load.
	 *
	 * @return void
	 */
	public static function install_table() {
		if ( get_option( 'dpt_agent_log_schema', '' ) === self::SCHEMA_VERSION ) {
			return;
		}

		$writer  = self::writer();
		$table   = self::table();
		$collate = $writer->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- this module's own table, created through dbDelta.
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				logged_at DATETIME NOT NULL,
				channel VARCHAR(20) NOT NULL DEFAULT '',
				app VARCHAR(100) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				action VARCHAR(40) NOT NULL DEFAULT '',
				object_type VARCHAR(40) NOT NULL DEFAULT '',
				object_subtype VARCHAR(60) NOT NULL DEFAULT '',
				object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				object_name VARCHAR(191) NOT NULL DEFAULT '',
				fields TEXT NULL,
				PRIMARY KEY  (id),
				KEY logged_at (logged_at),
				KEY object (object_type, object_id),
				KEY channel (channel)
			) {$collate};"
		);

		// dbDelta() reports nothing a caller can test: it returns the list of
		// changes it believes it made whether or not the queries succeeded. So
		// the stamp is only written once the table is actually there. Stamping
		// it regardless would mean a single failed CREATE - a transient
		// database error, a user without CREATE rights - permanently satisfies
		// the version guard above, and the log then stays empty forever while
		// flush() inserts into a table that does not exist. Leaving the option
		// unset costs one dbDelta per request until the table appears, and
		// nothing else; a run that fails a second time is a run that would
		// have been silently broken instead.
		//
		// LIKE reads _ as a single-character wildcard and every prefix has
		// one, so the name is escaped with esc_like() before prepare() quotes
		// it - otherwise wp_dpt_agent_log would match wpXdptXagentXlog and the
		// check would pass on a table this module never created.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking for the table this module just created; there is no cache for that.
		$found = $writer->get_var( $writer->prepare( 'SHOW TABLES LIKE %s', $writer->esc_like( $table ) ) );
		if ( $found !== $table ) {
			return;
		}

		update_option( 'dpt_agent_log_schema', self::SCHEMA_VERSION );
	}

	/**
	 * Empty the log.
	 *
	 * DELETE rather than TRUNCATE: TRUNCATE is a schema change on some
	 * configurations, cannot be rolled back, and this is reached from a
	 * button on an admin screen.
	 *
	 * @return void
	 */
	public static function clear() {
		$writer = self::writer();
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- own table, no user input in the statement.
		$writer->query( "DELETE FROM {$table}" );
	}
}
