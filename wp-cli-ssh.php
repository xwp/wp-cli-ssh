<?php

/**
 * WP-CLI require that facilitates invoking command on remote instance
 *
 * @package  wp-cli
 * @author   Jonathan Bardo <jonathan.bardo@x-team.com>
 * @author   Weston Ruter <weston@x-team.com>
 */

/**
 * Gather SSH config from global and project-specific config files
 */
$ssh_config = array();
foreach ( array( $this->global_config_path, $this->project_config_path ) as $config_path ) {
	$config = self::load_config( $config_path );
	if ( ! empty( $config['ssh'] ) ) {
		$ssh_config = array_merge( $ssh_config, $config['ssh'] );
	}
}
if ( empty( $ssh_config ) ) {
	return;
}

// Parse cli args to push to server
$has_url       = false;
$path          = null;
$target_server = null;
$cli_args      = array();

// @todo Better to use WP_CLI::get_configurator()->parse_args() here?
foreach ( array_slice( $GLOBALS['argv'], 1 ) as $arg ) {
	if ( preg_match( '#^--ssh-host=(.+)$#', $arg, $matches ) ) {
		$target_server = $matches[1];
	}
	else if ( preg_match( '#^--path=(.+)$#', $arg, $matches ) ) {
		$path = $matches[1];
	}
	else {
		if ( preg_match( '#^--url=#', $arg ) ) {
			$has_url = true;
		}
		$cli_args[] = $arg;
	}
}

// Check if a target is specified or fallback on local if not.
if ( $target_server && ! isset( $ssh_config[$target_server] ) ){
	WP_CLI::error( "The target host you specified doesn't exist in the config file." );
} else if ( ! $target_server ) {
	return;
}

// Revert back to default host if not explicitly specified
$target_server = ( $target_server ) ?: $ssh_config['default'];

// Check if the currently runned command is disabled on remote server
// Also check if we have a valid command
if ( isset( $ssh_config[$target_server]['disabled_commands'] ) ){
	$this->config['disabled_commands'] = $ssh_config[$target_server]['disabled_commands'];
}

// Check if command is valid or disabled **from core wp-cli
$r = $this->find_command_to_run( array_values( $cli_args ) );
if ( is_string( $r ) ) {
	WP_CLI::error( $r );
}

// Add default url from config is one is not set
if ( ! $has_url && ! empty( $ssh_config[$target_server]['url'] ) ) {
	$cli_args[] = '--url=' . $ssh_config[$target_server]['url'];
}

if ( ! $path && ! empty( $ssh_config[$target_server]['path'] ) ) {
	$path = $ssh_config[$target_server]['path'];
} else {
	WP_CLI::error( 'No path is specified' );
}

// Inline bash script
$cmd = <<<'BASH'
if command -v wp >/dev/null 2>&1; then
    wp_command=wp;
else
    wp_command=/tmp/wp-cli.phar;
    if [ ! -e $wp_command ]; then
        curl -L https://github.com/wp-cli/builds/blob/gh-pages/phar/wp-cli.phar?raw=true > $wp_command;
        chmod +x $wp_command;
    fi;
fi;
cd %s;
$wp_command
BASH;

// Replace path
$cmd = sprintf( $cmd, $path );

$cmd = trim( preg_replace( '/\s+/', ' ', $cmd ) );
$cmd .= ' ' . join( ' ', array_map( 'escapeshellarg', $cli_args ) );

// Escape command argument for each level of SSH tunnel inception
$cmd_prefix   = $ssh_config[$target_server]['cmd'];
$tunnel_depth = preg_match_all( '/(^|\s)(ssh|slogin)\s/', $cmd_prefix );
for ( $i = 0; $i < $tunnel_depth; $i += 1 ) {
	$cmd = escapeshellarg( $cmd );
}

//Replace placeholder by command
$cmd = str_replace( '%cmd%', $cmd, $cmd_prefix );

WP_CLI::log( sprintf( 'Connecting to remote host: %s', $target_server ) );

passthru( $cmd, $exit_code );

exit( $exit_code );
