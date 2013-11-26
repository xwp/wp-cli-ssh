<?php
/**
 * Invoke WP-CLI on another server via SSH from local machine
 *
 * @package  wp-cli
 * @author   Jonathan Bardo <jonathan.bardo@x-team.com>
 * @author   Weston Ruter <weston@x-team.com>
 */

/**
 * Copyright (c) 2013 X-Team (http://x-team.com/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Implements ssh command.
 */
class WP_CLI_SSH_Command extends WP_CLI_Command {

	private $global_config_path, $project_config_path;

	/**
	 * Forward command to remote host
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$runner = WP_CLI::get_runner();

		/**
		 * This script can either be supplied via a WP-CLI --require config, or
		 * it can be loaded via a Composer package.
		 * YES, the result is that this file is required twice. YES, this is hacky!
		 */
		$require_arg = sprintf( '--require=%s', __FILE__ );
		if ( empty( $runner ) ) {
			$GLOBALS['argv'][] = $require_arg;
			return;
		}

		// Parse cli args to push to server
		$has_url       = false;
		$path          = null;
		$target_server = null;
		$cli_args      = array();

		// @todo Better to use WP_CLI::get_configurator()->parse_args() here?
		foreach ( array_slice( $GLOBALS['argv'], 2 ) as $arg ) {
			// Remove what we added above the first time this file was loaded
			if ( $arg === $require_arg ) {
				continue;
			} else if ( preg_match( '#^--host=(.+)$#', $arg, $matches ) ) {
				$target_server = $matches[1];
			} else if ( preg_match( '#^--path=(.+)$#', $arg, $matches ) ) {
				$path = $matches[1];
			} else {
				if ( preg_match( '#^--url=#', $arg ) ) {
					$has_url = true;
				}
				$cli_args[] = $arg;
			}
		}

		// Remove duplicated ssh when there is a forgotten `alias wp="wp ssh --host=vagrant"`
		while ( ! empty( $cli_args ) && $cli_args[0] === 'ssh' ) {
			array_shift( $cli_args );
		}

		// Check if a target is specified or fallback on local if not.
		if ( ! isset( $assoc_args[$target_server] ) ){
			// Run local wp cli command
			$cmd = sprintf( '%s %s %s', PHP_BINARY, $GLOBALS['argv'][0], implode( ' ', $cli_args ) );
			passthru( $cmd, $exit_code );
			exit( $exit_code );
		} else {
			$ssh_config = $assoc_args[$target_server];
		}

		// Check if command is valid or disabled
		// Will output an error if the command has been disabled
		$r = $this->check_disabled_commands( array_values( $cli_args ), $ssh_config );

		// Add default url from config is one is not set
		if ( ! $has_url && ! empty( $ssh_config['url'] ) ) {
			$cli_args[] = '--url=' . $ssh_config['url'];
		}

		if ( ! $path && ! empty( $ssh_config['path'] ) ) {
			$path = $ssh_config['path'];
		} else {
			WP_CLI::error( 'No path is specified' );
		}

		// Inline bash script
		$cmd = '
			set -e;
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
		';

		// Replace path
		$cmd = sprintf( $cmd, escapeshellarg( $path ) );

		// Remove newlines in Bash script added just for readability
		$cmd  = trim( preg_replace( '/\s+/', ' ', $cmd ) );

		// Append WP-CLI args to command
		$cmd .= ' ' . join( ' ', array_map( 'escapeshellarg', $cli_args ) );

		// Escape command argument for each level of SSH tunnel inception, and pass along TTY state
		$is_tty       = function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );
		$cmd_prefix   = $ssh_config['cmd'];
		$cmd_prefix   = str_replace( '%pseudotty%', ( $is_tty ? '-t' : '-T' ), $cmd_prefix );
		$tunnel_depth = preg_match_all( '/(^|\s)(ssh|slogin)\s/', $cmd_prefix );
		for ( $i = 0; $i < $tunnel_depth; $i += 1 ) {
			$cmd = escapeshellarg( $cmd );
		}

		// Replace placeholder with command
		$cmd = str_replace( '%cmd%', $cmd, $cmd_prefix );

		if ( $is_tty ) { // they probably want this to be --quiet
			WP_CLI::log( sprintf( 'Connecting via ssh to host: %s', $target_server ) );
		}

		// Execute WP-CLI on remote server
		passthru( $cmd, $exit_code );

		// Prevent local machine's WP-CLI from executing further
		exit( $exit_code );
	}

	/**
	 * Check if the command run is disabled on local config
	 *
	 * @param array $args
	 * @param array $ssh_config
	 * 
	 * @return void|error
	 */
	private function check_disabled_commands( $args, $ssh_config ) {
		// Check if the currently runned command is disabled on remote server
		// Also check if we have a valid command
		if ( ! isset( $ssh_config['disabled_commands'] ) ) {
			return;
		} else {
			$disabled_commands = $ssh_config['disabled_commands'];
		}

		$cmd_path = array();

		while ( ! empty( $args ) ) {
			$cmd_path[] = array_shift($args);
			$full_name = implode( ' ', $cmd_path );

			// We don't check if the command exist as we might have community package installed on remote server an not local
			if ( in_array( $full_name, $disabled_commands ) ) {
				WP_CLI::error(
					sprintf(
						"The '%s' command has been disabled from the config file.",
						$full_name
					)
				);
			}
		}
	}

}

WP_CLI::add_command( 'ssh', 'WP_CLI_SSH_Command' );
