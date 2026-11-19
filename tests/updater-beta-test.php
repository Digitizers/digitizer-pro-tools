<?php
require __DIR__ . '/bootstrap.php';
foreach ( array( 'unset', 'off', 'on', 'string', 'integer' ) as $mode ) {
	$command = array( PHP_BINARY, __DIR__ . '/fixtures/updater-channel-probe.php', $mode );
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$stdout = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
	$code = proc_close( $process );
	$data = json_decode( $stdout, true );
	dpt_test_ok( 0 === $code && is_array( $data ), 'isolated channel probe runs: ' . $mode . ( $stderr ? ': ' . $stderr : '' ) );
	if ( ! is_array( $data ) ) { echo $stdout; continue; }
	$beta = 'on' === $mode;
	$version = $beta ? '1.55.0-beta.1' : '1.54.0';
	dpt_test_eq( $data['version'], $version, 'only explicit boolean true includes prereleases: ' . $mode );
	dpt_test_eq( $data['package'], 'https://example.test/install-' . $version . '.zip', 'channel keeps installation asset selection: ' . $mode );
	dpt_test_eq( $data['first_request'][0], $beta ? '/repos/:user/:repo/releases' : '/repos/:user/:repo/releases/latest', 'stable keeps latest endpoint; beta uses release list: ' . $mode );
	dpt_test_eq( $data['beta_only'], $beta ? '1.55.0-beta.1' : null, 'prerelease-only repository never leaks into stable: ' . $mode );
	dpt_test_eq( $data['draft_only'], null, 'drafts are excluded without tag/branch fallback: ' . $mode );
	dpt_test_eq( $data['empty'], null, 'no releases means no update, never a bare tag or branch: ' . $mode );
	dpt_test_eq( $data['builds'], 1, 'initialization remains idempotent: ' . $mode );
	dpt_test_eq( $data['state_option'], $beta ? 'external_updates-digitizer-pro-tools-beta' : '', 'beta cache is isolated while stable retains its existing cache: ' . $mode );
	dpt_test_eq( $data['auto_updates'], array( true, false, null ), 'WordPress auto-update decisions stay unchanged: ' . $mode );
	dpt_test_eq( $data['plugins_row'], '<a>Enable auto-updates</a>', 'Plugins-row control stays unchanged: ' . $mode );
	dpt_test_eq( $data['enrollment'], array(), 'channel never rewrites enrollment: ' . $mode );
	dpt_test_eq( $data['harness_failures'], 0, 'no PHP warnings or deprecations: ' . $mode );
}
exit( dpt_test_summary() ? 1 : 0 );
