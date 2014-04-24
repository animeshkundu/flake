<?php

class Daemon {

	private $fh;
	private $childPid;

	private $userId = null;
	private $termLimit = 20;
	private $didTick = false;

	private $pidfile = 'pid';
	private $logFile = 'log';
	private $errFile = 'log.err';

	private static $instance;

	private static function Shut_Down ( $msg )
	{
		self::Failed();
		die( $msg . "\n" );
	}

	private static function Show_Help ()
	{
		$cmd = $_SERVER['PHP_SELF'];
		echo "Usage: php $cmd [ status | start | stop | restart | reload | kill ]\n";
		exit( 0 );
	}

	private function Debug ( $msg )
	{
		/* echo $msg, "\n"; */
	}

	public function Do_Tick ()
	{
		$this->didTick = true;
	}

	private function Check_Directive ()
	{
		register_tick_function( array ( $this, 'Do_Tick' ) );
		usleep( 1 );

		if ( ! $this->didTick ) {
			$i = 1000;
			while ( $i -- );
		}

		unregister_tick_function( array ( $this, 'Do_Tick' ) );

		if ( ! $this->didTick ) {
			fwrite( STDERR, "It looks like `declare(ticks=1);` has not been " . "called, so signals to stop the daemon will fail. Ensure " . "that the root-level script calls this.\n" );
			exit( 1 );
		}
	}

	public function __construct ()
	{
		if ( self::$instance ) {
			self::Shut_Down( "Singletons only, please" );
		}
		self::$instance = true;

		$this->Check_Directive();
	}

	public function Set_User ( $username )
	{
		$info = posix_getpwnam( $username );

		if ( ! $info ) self::Shut_Down( "User '$username' not found" );

		$this->userId = $info['uid'];

		return $this;
	}

	public function Set_Process_Name ( $name )
	{
		if ( function_exists( 'cli_set_process_title' ) ) cli_set_process_title( $name );

		return $this;
	}

	public function Set_Pid_File_Location ( $path )
	{
		if ( ! is_string( $path ) ) throw new InvalidArgumentException( "Pidfile path must be a string" );

		$this->pidfile = $path;

		return $this;
	}

	public function Set_Stdout_File_Location ( $path )
	{
		if ( ! is_string( $path ) ) throw new InvalidArgumentException( "Stdout path must be a string" );

		$this->logFile = $path;

		return $this;
	}

	public function Set_Stderr_File_Location ( $path )
	{
		if ( ! is_string( $path ) ) throw new InvalidArgumentException( "Stderr path must be a string" );

		$this->errFile = $path;

		return $this;
	}

	public function Set_Terminate_Limit ( $seconds )
	{
		if ( ! is_int( $seconds ) || $seconds < 1 ) throw new InvalidArgumentException( "Limit must be a positive int" );

		$this->termLimit = $seconds;

		return $this;
	}

	public function Auto_Run ()
	{
		if ( $_SERVER['argc'] < 2 ) self::Show_Help();

		$cmd = strtolower( end( $_SERVER['argv'] ) );

		switch ( $cmd ) {
		case 'start' :
		case 'stop' :
		case 'restart' :
		case 'reload' :
		case 'status' :
		case 'kill' :
			call_user_func( array ( $this, ucfirst( $cmd ) ) );
			break;
		default :
			self::Show_Help();
			break;
		}
	}

	private function Start ()
	{
		self::Show( "Starting..." );

		$this->fh = fopen( $this->pidfile, 'c+' );

		if ( ! flock( $this->fh, LOCK_EX | LOCK_NB ) ) self::Shut_Down( "Could not lock the pidfile. This daemon may already " . "be running." );

		$this->Debug( "About to fork" );
		$pid = pcntl_fork();
		switch ( $pid ) {
		case - 1 :
			self::Shut_Down( "Could not fork" );
			break;

		case 0 :
			$this->childPid = getmypid();
			$this->Debug( "Forked - child process ($this->childPid)" );
			break;

		default :
			$me = getmypid();
			$this->Debug( "Forked - parent process ($me -> $pid)" );
			fseek( $this->fh, 0 );
			ftruncate( $this->fh, 0 );
			fwrite( $this->fh, $pid );
			fflush( $this->fh );
			$this->Debug( "Parent wrote PID" );
			exit();
		}

		if ( posix_setsid() === - 1 ) self::Shut_Down( "Child process could not detach from terminal." );

		if ( null !== $this->userId ) {
			if ( ! posix_setuid( $this->userId ) ) self::Shut_Down( "Could not change user. Try running this program" . " as root." );
		}

		self::Ok();


		$this->Debug( "Resetting file descriptors" );
		fclose( STDIN );
		fclose( STDOUT );
		fclose( STDERR );

		$this->stdin = fopen( '/dev/null', 'r' );
		$this->stdout = fopen( $this->logFile, 'a+' );
		$this->stderr = fopen( $this->errFile, 'a+' );
		$this->Debug( "Reopened file descriptors" );
		$this->Debug( "Executing original script" );

		pcntl_signal( SIGTERM, function  ()
		{
			exit();
		} );
	}

	private function Terminate ( $msg, $signal )
	{
		self::Show( $msg );
		$pid = $this->Get_Child_Pid();

		if ( false === $pid ) {
			self::Failed();
			echo "No PID file found\n";
			return;
		}

		if ( ! posix_kill( $pid, $signal ) ) {
			self::Failed();
			echo "Process $pid not running!\n";
			return;
		}
		$i = 0;
		while ( posix_kill( $pid, 0 ) ) {
			if ( ++ $i >= $this->termLimit ) {
				self::Shut_Down( "Process $pid did not terminate after $i seconds" );
			}
			self::Show( '.' );
			sleep( 1 );
		}
		self::Ok();
	}

	public function __destruct ()
	{
		if ( getmypid() == $this->childPid ) {
			unlink( $this->pidfile );
		}
	}

	private function Stop ( $exit = true )
	{
		$this->Terminate( 'Stopping', SIGTERM );
		$exit && exit();
	}

	private function Restart ()
	{
		$this->Stop( false );
		$this->Start();
	}

	private function Reload ()
	{
		$pid = $this->Get_Child_Pid();
		self::Show( "Sending SIGUSR1" );
		if ( $pid && posix_kill( $pid, SIGUSR1 ) ) {
			self::Ok();
		} else {
			self::Failed();
		}
		exit();
	}

	private function Status ()
	{
		$pid = $this->Get_Child_Pid();

		if ( ! $pid ) {
			echo "Process is stopped\n";
			exit( 3 );
		}

		if ( posix_kill( $pid, 0 ) ) {
			echo "Process (pid $pid) is running...\n";
			exit( 0 );
		} else {
			echo "Process dead but pid file exists\n";
			exit( 1 );
		}
	}

	private function Kill ()
	{
		$this->Terminate( 'Sending SIGKILL', SIGKILL );
		exit();
	}

	private function Get_Child_Pid ()
	{
		return file_exists( $this->pidfile ) ? file_get_contents( $this->pidfile ) : false;
	}

	private static $chars = 0;

	private static function Show ( $text )
	{
		echo $text;
		self::$chars += strlen( $text );
	}

	private static function Ok ()
	{
		echo str_repeat( ' ', 59 - self::$chars );
		echo "[\033[0;32m  OK  \033[0m]\n";
		self::$chars = 0;
	}

	private static function Failed ()
	{
		echo str_repeat( ' ', 59 - self::$chars );
		echo "[\033[0;31mFAILED\033[0m]\n";
		self::$chars = 0;
	}

}