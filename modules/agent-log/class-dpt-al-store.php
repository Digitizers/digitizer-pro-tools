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
	 * The columns, their defaults and their printf formats, in one place so
	 * insert() cannot pass a value without a format - which is what makes a
	 * value reach the database unescaped.
	 *
	 * @return array
	 */
	private static function columns() {
		return array(
			'logged_at'      => array( '', '%s' ),
			'channel'        => array( '', '%s' ),
			'app'            => array( '', '%s' ),
			'user_id'        => array( 0, '%d' ),
			'action'         => array( '', '%s' ),
			'object_type'    => array( '', '%s' ),
			'object_subtype' => array( '', '%s' ),
			'object_id'      => array( 0, '%d' ),
			'object_name'    => array( '', '%s' ),
			'fields'         => array( array(), '%s' ),
		);
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
			list( $default, $format ) = $spec;
			$value                    = array_key_exists( $name, $row ) ? $row[ $name ] : $default;

			if ( 'fields' === $name ) {
				$value = wp_json_encode( array_values( array_map( 'strval', (array) $value ) ) );
				if ( ! is_string( $value ) ) {
					// wp_json_encode() can fail on malformed UTF-8. An
					// unwritable field list must not lose the row that says
					// the object changed at all.
					$value = '[]';
				}
			} elseif ( '%d' === $format ) {
				$value = (int) $value;
			} else {
				$value = is_scalar( $value ) ? (string) $value : '';
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
}
