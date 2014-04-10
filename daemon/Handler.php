<?php

namespace Daemon;

class Handler {

	private static $pid;
	private static $status;
	private static $logdir;

	public static function __callstatic ( $name, $argv )
	{
		$argv = $argv[0];
		if ( ! isset( $argv[1] ) ) die( 'Syntax: ' . $argv[0] . ' start|stop|restart|status' . "\n" );

		$action = $argv[1];
		$a = explode( '|', trim( @file_get_contents( DAEMON_PID_FILENAME ) ) );
		self::$status = $a[0];
		self::$pid = ( int ) $a[1];
		self::$logdir = 'log/' . DAEMON_NAME . '_' . date('Y-m-d') . '.txt';

		if ( in_array( $name, array ( 'start', 'stop', 'restart', 'status' ) ) )
			self::$name();
		else
			die( 'Syntax: ' . $argv[0] . ' start|stop|restart|status' . "\n" );
	}

	private static function start ()
	{
		if ( file_exists( '/proc/' . self::$pid ) ) die( DAEMON_NAME . ' already started [' . self::$pid . ']' . "\n" );

		file_put_contents( DAEMON_DIR_PATH . DAEMON_PID_FILENAME, 'started|0', ( int ) FILE_TEXT );

		$forkPid = pcntl_fork();

		if ( $forkPid == - 1 ) die( 'Error while starting ' . DAEMON_NAME . ': fork failed' . "\n" );

		if ( $forkPid == 0 ) {
			exec( 'cd ' . DAEMON_DIR_PATH . '; php ' . DAEMON_FILENAME . ' >>' . self::$logdir . ' 2>&1' );
		} else {
			echo DAEMON_NAME . ' started' . "\n";
			exit( 0 );
		}
	}

	private static function stop ()
	{
		if ( ! file_exists( '/proc/' . self::$pid ) ) die( DAEMON_NAME . ' not running' . "\n" );

		file_put_contents( DAEMON_DIR_PATH . DAEMON_PID_FILENAME, 'stopped|0', ( int ) FILE_TEXT );

		exec( 'kill -9 ' . self::$pid );

		echo DAEMON_NAME . ' stopped [' . self::$pid . ']' . "\n";
	}

	private static function restart ()
	{
		if ( file_exists( '/proc/' . self::$pid ) ) {
			exec( 'kill -9 ' . self::$pid );

			echo DAEMON_NAME . ' stopped [' . self::$pid . ']' . "\n";
		}

		file_put_contents( DAEMON_DIR_PATH . DAEMON_PID_FILENAME, 'started|0', ( int ) FILE_TEXT );

		$forkPid = pcntl_fork();

		if ( $forkPid == - 1 ) die( 'Error while starting ' . DAEMON_NAME . ' : fork failed' . "\n" );

		if ( $forkPid == 0 ) {
			exec( 'cd ' . DAEMON_DIR_PATH . '; php ' . DAEMON_FILENAME . ' >>' . self::$logdir . ' 2>&1' );
		} else {
			echo DAEMON_NAME . ' started' . "\n";
			exit( 0 );
		}
	}

	private static function status ()
	{
		if ( file_exists( '/proc/' . self::$pid ) ) {
			echo DAEMON_NAME . ' running' . "\n";
		} else {
			echo DAEMON_NAME . ' stopped' . "\n";
		}
	}

}
