<?php
/**
 * Agent Log module - what this request changed, gathered before it is written.
 *
 * A single REST call that updates a post fires save_post once and the meta
 * hooks once per key. Writing a row per hook would turn one edit into nine
 * rows and make the log something you reassemble by eye rather than read. So
 * changes accumulate here, keyed by object, and the request writes once.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Buffer {

	const DEFAULT_MAX_AGE_DAYS = 30;
	const DEFAULT_MAX_ROWS     = 20000;

	/** @var array */
	private static $pending = array();

	/**
	 * How strongly an action describes what happened to an object, when more
	 * than one reached the buffer in one request. An update is a fact about
	 * an object's contents, the weakest claim. Activation, deactivation and
	 * switching are facts about its state - the same class of fact as
	 * creation and deletion, which are facts about its existence - so all
	 * five outrank a mere update. An action this table does not know about
	 * ranks 0, below even "updated", so it can never silently outrank one
	 * the table does know. Rank breaks ties in favour of the later action:
	 * see record(), where equal rank means two claims of the same strength
	 * and the second one is the one that is still true at the end.
	 */
	private static $rank = array(
		'updated'     => 1,
		'activated'   => 2,
		'deactivated' => 2,
		'switched'    => 2,
		'created'     => 3,
		'deleted'     => 4,
	);

	/**
	 * Note that something changed.
	 *
	 * @param string $type    post|term|attachment|user|plugin|theme|option.
	 * @param string $subtype Post type, taxonomy, plugin file - '' when none.
	 * @param int    $id      Object id, 0 for objects without one.
	 * @param string $action  created|updated|deleted|activated|deactivated|switched.
	 * @param string $name    Human-readable name.
	 * @param array  $fields  Field names touched. Never values.
	 * @return void
	 */
	public static function record( $type, $subtype, $id, $action, $name = '', $fields = array() ) {
		// Objects with a real id are keyed on it. Plugins, themes and options
		// pass id 0, so keying on id alone would collapse every plugin
		// activated in one request onto the same "plugin:0" entry - key
		// those on whatever actually identifies the object instead.
		$key = $type . ':' . ( (int) $id > 0
			? (int) $id
			: ( '' !== (string) $subtype ? (string) $subtype : (string) $name ) );

		if ( ! isset( self::$pending[ $key ] ) ) {
			self::$pending[ $key ] = array(
				'object_type'    => (string) $type,
				'object_subtype' => (string) $subtype,
				'object_id'      => (int) $id,
				'object_name'    => (string) $name,
				'action'         => (string) $action,
				'fields'         => array(),
			);
		} else {
			$held = self::$pending[ $key ]['action'];
			$new  = (string) $action;
			$a    = isset( self::$rank[ $held ] ) ? self::$rank[ $held ] : 0;
			$b    = isset( self::$rank[ $new ] ) ? self::$rank[ $new ] : 0;
			// Greater *or equal*: two actions of the same rank are two state
			// changes, and the later one is the state the request left the
			// object in. A plugin activated and then deactivated in one
			// request is deactivated, whatever order the hooks fired in, and
			// a row saying "activated" would be the log contradicting the
			// site. Ranks that differ are unaffected, so a create still
			// outranks the update that followed it.
			if ( $b >= $a ) {
				self::$pending[ $key ]['action'] = $new;
			}
			if ( '' !== (string) $name ) {
				self::$pending[ $key ]['object_name'] = (string) $name;
			}
		}

		foreach ( (array) $fields as $field ) {
			if ( is_scalar( $field ) && '' !== (string) $field ) {
				self::$pending[ $key ]['fields'][ (string) $field ] = true;
			}
		}
	}

	/**
	 * @return array
	 */
	public static function pending() {
		return self::$pending;
	}

	/**
	 * @return void
	 */
	public static function reset() {
		self::$pending = array();
	}

	/**
	 * The rows this request should write.
	 *
	 * @param string $channel Channel name.
	 * @param string $app     Application name, '' when unknown.
	 * @param int    $user_id Acting user, 0 under cron.
	 * @param int    $now     Current timestamp.
	 * @return array
	 */
	public static function rows( $channel, $app, $user_id, $now ) {
		$rows = array();
		foreach ( self::$pending as $entry ) {
			$entry['logged_at'] = gmdate( 'Y-m-d H:i:s', (int) $now );
			$entry['channel']   = (string) $channel;
			$entry['app']       = (string) $app;
			$entry['user_id']   = (int) $user_id;
			$entry['fields']    = array_keys( $entry['fields'] );
			$rows[]             = $entry;
		}
		return $rows;
	}

	/**
	 * @return int Days to keep, 0 or less to keep forever.
	 */
	public static function max_age_days() {
		/**
		 * Filter how many days of agent activity are kept.
		 *
		 * @param int $days Days, 0 or less to disable the age bound.
		 */
		return (int) apply_filters( 'dpt_agent_log_max_age_days', self::DEFAULT_MAX_AGE_DAYS );
	}

	/**
	 * @return int Rows to keep, 0 or less for no limit.
	 */
	public static function max_rows() {
		/**
		 * Filter how many rows of agent activity are kept.
		 *
		 * @param int $rows Rows, 0 or less to disable the row bound.
		 */
		return (int) apply_filters( 'dpt_agent_log_max_rows', self::DEFAULT_MAX_ROWS );
	}
}
