# Finding: how does core name the application password that authenticated a REST request?

Source: WordPress core `7.1` (downloaded fresh via `https://wordpress.org/latest.zip` into
`/private/tmp/.../scratchpad/wpcore/wordpress`, not committed to this repo). Version confirmed at
`wordpress/wp-includes/version.php:19`: `$wp_version = '7.1';`.

All line numbers below refer to that downloaded core tree.

## Question 1: what identifies *which* password was used?

Two things identify it, and they arrive together as action arguments — core does **not** expose
this via user meta lookup alone or via a REST-route argument; it is delivered through the
`application_password_did_authenticate` action, and core's own listener for that action stashes it
in a PHP global.

`wp-includes/user.php:497` (inside `wp_authenticate_application_password()`, after the supplied
password is matched against a stored hash on line ~455 `foreach ( $hashed_passwords as $key =>
$item )`):

```php
// wp-includes/user.php:490-497
WP_Application_Passwords::record_application_password_usage( $user->ID, $item['uuid'] );

/**
 * Fires after an application password was used for authentication.
 *
 * @since 5.6.0
 *
 * @param WP_User $user The user who was authenticated.
 * @param array   $item The application password used.
 */
do_action( 'application_password_did_authenticate', $user, $item );
```

`$item` is one element of the array returned by `WP_Application_Passwords::get_user_application_passwords()`
(`wp-includes/class-wp-application-passwords.php:175-190`), i.e. the raw stored record: `uuid`,
`app_id`, `name`, `password` (hash), `created`, `last_used`, `last_ip`.

Core registers its own listener for that action at `wp-includes/default-filters.php:344`:

```php
add_action( 'application_password_did_authenticate', 'rest_application_password_collect_status', 10, 2 );
```

which calls `rest_application_password_collect_status()` (`wp-includes/rest-api.php:1210-1219`):

```php
function rest_application_password_collect_status( $user_or_error, $app_password = array() ) {
	global $wp_rest_application_password_status, $wp_rest_application_password_uuid;

	$wp_rest_application_password_status = $user_or_error;

	if ( empty( $app_password['uuid'] ) ) {
		$wp_rest_application_password_uuid = null;
	} else {
		$wp_rest_application_password_uuid = $app_password['uuid'];
	}
}
```

Core then exposes that global through a public accessor, `rest_get_authenticated_app_password()`
(`wp-includes/rest-api.php:1222-1235`):

```php
/**
 * Gets the Application Password used for authenticating the request.
 *
 * @since 5.7.0
 *
 * @global string|null $wp_rest_application_password_uuid
 *
 * @return string|null The Application Password UUID, or null if Application Passwords was not used.
 */
function rest_get_authenticated_app_password() {
	global $wp_rest_application_password_uuid;

	return $wp_rest_application_password_uuid;
}
```

**Answer:** the identifier is the application password's **UUID** (`$item['uuid']`), delivered as
an action argument on `application_password_did_authenticate` and additionally cached by core
itself in the global `$wp_rest_application_password_uuid`, retrievable at any later point in the
same request via the public function `rest_get_authenticated_app_password()`. It is *not* a route
argument and *not* directly a user-meta key you'd query fresh — user meta is where the full
records (including `name`) live, keyed by user ID, but you still need the UUID to pick the right
one out of the array.

The `application_password_did_authenticate` action is wired unconditionally in
`default-filters.php` (not gated to `REST_REQUEST`), but it only fires from inside
`wp_authenticate_application_password()`, which itself only proceeds when
`application_password_is_api_request` is true (REST or XML-RPC) — see
`wp-includes/user.php:386-399`. So for a plain cookie-authenticated web request, this action never
fires and `rest_get_authenticated_app_password()` returns `null`. This satisfies the "never
fabricate an identity" constraint: when it's null, there is nothing to attribute to, and the
module must return `''` rather than guess.

## Question 2: does the identifier reach a `shutdown` callback, or only during authentication?

**It reaches `shutdown`.** `$wp_rest_application_password_uuid` is a plain PHP global set during
authentication (which runs early, via the `determine_current_user`/`authenticate` filters, long
before `shutdown` fires). PHP globals persist for the lifetime of the request/process. A shutdown
callback that does:

```php
global $wp_rest_application_password_uuid;
```

(or simply calls `rest_get_authenticated_app_password()`, which does the same `global` internally
— `wp-includes/rest-api.php:1232`) will see the same value that was set during authentication,
provided nothing unset the global in between. Nothing in core does that — grepped
`wp-includes/*.php` for `$wp_rest_application_password_uuid` and the only writes are the one shown
above in `rest_application_password_collect_status()`; there is no reset/unset anywhere else in
core.

**Answer:** the module does **not** need to capture the UUID early in a custom hook on
`application_password_did_authenticate`. It can call `rest_get_authenticated_app_password()`
directly from a `shutdown` callback and get the same value core's own REST introspection endpoint
(`wp-json`, application-passwords index) would return.

One caveat worth recording: this relies on core's own `rest_application_password_collect_status()`
listener actually being registered, which happens unconditionally in `default-filters.php:344` on
every request (that file is loaded unconditionally by `wp-settings.php`), so it is not something a
site could disable short of removing the action with `remove_action()`. `DPT_AL_Channel::app_name()`
should treat "function doesn't exist" or "returns null/empty" as "no application password was
used", not as an error.

## Question 3: what field on the password record holds the human-readable name?

The `name` field, as typed by the user when creating the password. Set (after sanitisation) in
`WP_Application_Passwords::create_new_application_password()`:

`wp-includes/class-wp-application-passwords.php:89-109`:

```php
public static function create_new_application_password( $user_id, $args = array() ) {
	if ( ! empty( $args['name'] ) ) {
		$args['name'] = sanitize_text_field( $args['name'] );
	}

	if ( empty( $args['name'] ) ) {
		return new WP_Error( 'application_password_empty_name', __( 'An application name is required to create an application password.' ), array( 'status' => 400 ) );
	}

	$new_password    = wp_generate_password( static::PW_LENGTH, false );
	$hashed_password = self::hash_password( $new_password );

	$new_item = array(
		'uuid'      => wp_generate_uuid4(),
		'app_id'    => empty( $args['app_id'] ) ? '' : $args['app_id'],
		'name'      => $args['name'],
		'password'  => $hashed_password,
		'created'   => time(),
		'last_used' => null,
		'last_ip'   => null,
	);
	...
```

The name is required (empty name is rejected with `application_password_empty_name`), sanitised
with `sanitize_text_field()`, and stored under user meta key `USERMETA_KEY_APPLICATION_PASSWORDS`
(`wp-includes/class-wp-application-passwords.php:176`, `get_user_meta( $user_id,
static::USERMETA_KEY_APPLICATION_PASSWORDS, true )`).

To go from "authenticated UUID" to "human-readable name", the module needs one more lookup — core
provides it: `WP_Application_Passwords::get_user_application_password( $user_id, $uuid )`
(`wp-includes/class-wp-application-passwords.php:217-227`):

```php
public static function get_user_application_password( $user_id, $uuid ) {
	$passwords = static::get_user_application_passwords( $user_id );

	foreach ( $passwords as $password ) {
		if ( $password['uuid'] === $uuid ) {
			return $password;
		}
	}

	return null;
}
```

This returns the same associative array shown above (or `null` if the UUID no longer exists —
e.g. the password was deleted between authentication and shutdown), from which `$password['name']`
is the human-readable name.

**Answer:** `name`, sourced from `WP_Application_Passwords::get_user_application_password( $user_id,
$uuid )['name']`.

## What `DPT_AL_Channel::app_name()` should do

Given the above, the implementation for Task 1 is a two-step read, both against core public API,
no capture-early plumbing required:

```php
$uuid = function_exists( 'rest_get_authenticated_app_password' )
	? rest_get_authenticated_app_password()
	: null;

if ( ! $uuid ) {
	return '';
}

$user_id = get_current_user_id();
if ( ! $user_id ) {
	return '';
}

$record = WP_Application_Passwords::get_user_application_password( $user_id, $uuid );

return ( is_array( $record ) && ! empty( $record['name'] ) ) ? (string) $record['name'] : '';
```

This can run from a `shutdown` hook (Question 2's finding) as easily as from inside the REST
dispatch cycle. It never touches User-Agent or IP: if application passwords were not used to
authenticate this request, `rest_get_authenticated_app_password()` returns `null` and the function
returns `''`, per the brief's hard constraint against fabricated attribution.
