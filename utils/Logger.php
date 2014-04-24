<?php

class Logger {

	private static $fp = null;
	private static $date = null;

	public static function Log ( $message, $severityType = 'INFO', $summary = 'MISC', $verbose = 0 )
	{
		$date = date( 'd-m-Y' );

		if ( self::$date != $date || ! self::$fp ) {
			$file = 'logs/psentropi_' . $date . '.log';

			self::$date = $date;
			self::$fp = fopen( $file, "a" );
		}

		$logMessage = date( "[d-m-Y G:i:s]" ) . " [ " . $severityType . " ] [ " . $summary . " ] [ " . $message . " ] " . PHP_EOL;

		if ( ! self::$fp || fwrite( self::$fp, $logMessage ) == false ) {
			$file = 'logs/psentropi_' . $date . '.log';
			self::$fp = fopen( $file, "a" );

			if ( self::$fp ) fwrite( self::$fp, $logMessage );
		}
	}

}