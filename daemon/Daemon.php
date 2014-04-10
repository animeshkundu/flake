<?php

namespace Daemon;

abstract class Daemon {

	/**
	 * Check if the script is already running, if yes kill the script
	 */
	public static function _ ( $file, $memory = '256M', $time = 0 )
	{
		if ( file_exists( $file ) ) {
			$a = explode( '|', trim( file_get_contents( $file ) ) );

			/* if the file was well formed */
			if ( sizeof( $a ) == 2 ) {
				$status = $a[0];
				$pid = ( int ) $a[1];

				/* die if the daemon is stopped */
				if ( $status == 'stoppped' ) die();

				/* kill if the process does exist already */
				if ( $pid > 1 && file_exists( '/proc/' . $pid ) ) {
					die();
				}
			}
		}

		if ( ! file_put_contents( $file, 'started|' . getmypid() . "\n", ( int ) FILE_TEXT ) ) {
			die( 'unable to write in file' );
		}

		/* make the file accessible */
		chmod( $file, 0777 );

		/* raise memory limit and max execution time. */
		ini_set( 'memory_limit', $memory );
		ini_set( 'max_execution_time', $time );
	}

}