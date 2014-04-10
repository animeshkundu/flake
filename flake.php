<?php

namespace Flake;

const VERSION = "0.01";

abstract class Exception extends \Exception {
	public $addr;

	public function __construct ( $errmsg, $errno, $addr )
	{
		parent::__construct( $errmsg, $errno );
		$this->addr = $addr;
	}

}

class Server_Exception extends Exception {
	public $listener;

	public function __construct ( $errmsg, $errno, $addr, Listener $listener = NULL )
	{
		parent::__construct( $errmsg, $errno, $addr );
		$this->listener = $listener;
	}

}

class Client_Exception extends Exception {
	public $handler;

	public function __construct ( $errmsg, $errno, $addr, Handler $handler = NULL )
	{
		parent::__construct( $errmsg, $errno, $addr );
		$this->handler = $handler;
	}

}

/* Base socket class */
class Socket {
	const DEFAULT_READ_LENGTH = 16384;

	public $id; /* Internal Socket unique ID */
	public $fd; /* Socket stream descriptor */
	public $connected = false;
	public $pending_connect = false;
	public $pending_crypto = false;
	public $blocked = false;
	public $block_reads = false;
	protected $context;
	public $crypto_type;
	public $handler;
	private static $sck_cnt; /* Static instance counter */

	public function __construct ( $fd = false, $crypto_type = false )
	{
		if ( $fd === false ) {
			$this->context = stream_context_create();
		} else {
			$this->fd = $fd;
			$this->connected = true;
			$this->Set_Blocking( false );
			$this->Set_Timeout( 0 );

			if ( $crypto_type ) $this->crypto_type = $crypto_type;
		}

		$this->id = ++ Socket::$sck_cnt;
	}

	public function Get_Options ()
	{
		if ( $this->fd ) {
			return stream_context_get_options( $this->fd );
		} else {
			return stream_context_get_options( $this->context );
		}
	}

	public function Set_Option ( $wrapper, $opt, $val )
	{
		if ( $this->fd ) {
			return stream_context_set_option( $this->fd, $wrapper, $opt, $val );
		} else {
			return stream_context_set_option( $this->context, $wrapper, $opt, $val );
		}
	}

	protected function Set_Timeout ( $timeout )
	{
		return stream_set_timeout( $this->fd, $timeout );
	}

	protected function Set_Blocking ( $block )
	{
		return stream_set_blocking( $this->fd, $block );
	}


	/* Flag the socket so that the main loop won't read from it even if data is available.
	 * This can be used to implement flow control when proxying data between two asymetric connections for example. */
	public function Block_Reads ( $block )
	{
		$ret = $this->block_reads;
		$this->block_reads = $block;
		return $ret;
	}

	/* Set the stream write buffer (PHP defaults to 8192 bytes) */
	public function Set_Write_Buffer ( $buffer_size )
	{
		return stream_set_write_buffer( $this->fd, $buffer_size );
	}

	public function Enable_Crypto ( $enable = true, $type = false )
	{
		if ( $type ) $this->crypto_type = $type;
		$ret = @stream_socket_enable_crypto( $this->fd, $enable, $this->crypto_type );
		$this->pending_crypto = $ret === 0;
		return $ret;
	}

	public function Setup ()
	{
		if ( isset( $this->crypto_type ) ) return $this->Enable_Crypto();
		return true;
	}

	public function Get_Name ()
	{
		return stream_socket_get_name( $this->fd, false );
	}

	/* Remote socket name. */
	public function Get_Peer_Name ()
	{
		return stream_socket_get_name( $this->fd, true );
	}

	public function Read ()
	{
		return fread( $this->fd, self::DEFAULT_READ_LENGTH );
	}

	/* Read data from non connected socket. */
	public function Read_From ( &$addr, $len = 16384 )
	{
		return stream_socket_recvfrom( $this->fd, $len, NULL, $addr );
	}

	public function Write ( $data )
	{
		$nb = fwrite( $this->fd, $data );
		if ( isset( $data[$nb] ) ) $this->blocked = true;
		return $nb;
	}

	/* Write data to a non connected socket */
	public function Write_To ( $to, $data )
	{
		return stream_socket_sendto( $this->fd, $data, NULL, $to );
	}

	/* Write data from stream to socket */
	public function Write_From_Stream ( $stream, $len = 16384 )
	{
		return stream_copy_to_stream( $stream, $this->fd, $len );
	}

	/* Query end of stream status */
	public function Eof ()
	{
		$fd = $this->fd;
		if ( ! is_resource( $fd ) ) return true;
		stream_socket_recvfrom( $fd, 1, STREAM_PEEK );
		return feof( $fd );
	}

	public function Close ()
	{
		@fclose( $this->fd );
		$this->connected = $this->pending_connect = false;
	}

	public function __destruct ()
	{
		Core::Free_Write_Buffers( $this->id );
		$this->Close();
	}

}


class Server_Socket extends Socket {

	public $address;
	private $real_address;

	public function __construct ( $addr )
	{
		parent::__construct();
		$this->address = $addr;
		$proto = strtolower( strtok( $addr, ":" ) );

		if ( ($proto === "udp") || ($proto === "unix") ) {
			$this->real_address = $addr;
		} else {
			$this->real_address = "tcp:" . strtok( "" );
			if ( $proto !== "tcp" ) switch ( $proto ) {
			case "ssl" :
				$this->crypto_type = STREAM_CRYPTO_METHOD_SSLv23_SERVER;
				break;
			case "tls" :
				$this->crypto_type = STREAM_CRYPTO_METHOD_TLS_SERVER;
				break;
			case "sslv2" :
				$this->crypto_type = STREAM_CRYPTO_METHOD_SSLv2_SERVER;
				break;
			case "sslv3" :
				$this->crypto_type = STREAM_CRYPTO_METHOD_SSLv3_SERVER;
				break;

			default :
				if ( defined( $cname = "STREAM_CRYPTO_METHOD_" . strtoupper( $proto ) . "_SERVER" ) ) {
					$this->crypto_type = constant( $cname );
				} else {
					throw new Server_Exception( "unknown transport/crypto type '{$proto}'" );
				}
			}
		}
	}

	public function Listen ( $bind_only = false )
	{
		$errno = $errstr = false;
		$this->fd = @stream_socket_server( $this->real_address, $errno, $errstr, STREAM_SERVER_BIND | ($bind_only ? 0 : STREAM_SERVER_LISTEN), $this->context );
		if ( $this->fd === false ) {
			throw new Server_Exception( "cannot listen to {$this->real_address}: {$errstr}", $errno, $this->real_address );
		}

		$this->Set_Blocking( false );
		$this->Set_Timeout( 0 );

		return true;
	}

	public function Accept ()
	{
		return @stream_socket_accept( $this->fd, 0 );
	}

}


class Client_Socket extends Socket {

	const CONNECT_TIMEOUT = 10;

	public $address;
	public $connect_timeout;

	public function __construct ( $addr )
	{
		parent::__construct();
		$this->address = $addr;
		$proto = strtolower( strtok( $addr, ":" ) );
		$s = strtok( "" );

		if ( ($proto === "udp") || ($proto === "unix") ) {
			$this->real_address = $addr;
		} else {
			$this->real_address = "tcp:" . $s;

			if ( $proto != "tcp" ) switch ( $proto ) {
			case "ssl" :
				$this->crypto_type = STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
				break;
			case "tls" :
				$this->crypto_type = STREAM_CRYPTO_METHOD_TLS_CLIENT;
				break;
			case "sslv2" :
				$this->crypto_type = STREAM_CRYPTO_METHOD_SSLv2_CLIENT;
				break;
			case "sslv3" :
				$this->crypto_type = STREAM_CRYPTO_METHOD_SSLv3_CLIENT;
				break;

			default :
				if ( defined( $cname = "STREAM_CRYPTO_METHOD_" . strtoupper( $proto ) . "_CLIENT" ) ) $this->crypto_type = constant( $cname );
			}
		}
	}

	public function Connect ( $timeout = false )
	{
		$errno = $errstr = false;
		$this->fd = @stream_socket_client( $this->real_address, $errno, $errstr, 3, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT, $this->context );

		if ( $this->fd === false ) {
			throw new Client_Exception( "cannot connect to {$this->real_address}: {$errstr}", $errno, $this->real_address );
		}

		if ( $timeout === false ) $timeout = self::CONNECT_TIMEOUT;

		$this->connect_timeout = microtime( true ) + $timeout;
		$this->pending_connect = true;
		$this->connected = false;
		$this->Set_Blocking( false );
		$this->Set_Timeout( 0 );

		return true;
	}

}


class IPC_Socket extends Socket {

	const IPC_MAX_PACKET_SIZE = 1048576;

	public $pid;

	public function __construct ( $fd, $pid = false )
	{
		parent::__construct( $fd );
		$this->Set_Write_Buffer( self::IPC_MAX_PACKET_SIZE );
		$this->pid = $pid;
	}

	public function Read ()
	{
		return fread( $this->fd, self::IPC_MAX_PACKET_SIZE );
	}

	/* Creates a pair of connected, indistinguishable pipes */
	static public function Pair ( $domain = STREAM_PF_UNIX, $type = STREAM_SOCK_DGRAM, $proto = 0 )
	{
		list ( $s1, $s2 ) = stream_socket_pair( $domain, $type, $proto );
		return array ( new IPC_Socket( $s1 ), new IPC_Socket( $s2 ) );
	}

	/* Ask the master process for object data */
	public function Ask_Master ( $request, $need_response = true )
	{
		$this->Write( serialize( $request ) );
		if ( ! $need_response ) return;
		$rfd = array ( $this->fd );
		$dfd = array ();

		if ( @stream_select( $rfd, $dfd, $dfd, 600 ) ) return unserialize( $this->Read() );
	}

}


/* Do not instanciate Timer but use the Core::New_Timer() method instead */
class Timer {

	public $microtime; /* System time for timer activation */
	public $callback;
	public $active = true;

	public function __construct ( $time, $callback )
	{
		$this->microtime = $time;
		$this->callback = $callback;
	}


	/* Timers are activated by default, and Activate should only be used after a call do Deactivate() */
	public function Activate ()
	{
		$this->active = true;
	}

	public function Deactivate ()
	{
		$this->active = false;
	}

}


/* Write buffer interface */
interface I_Write_Buffer {

	public function __construct ( Socket $socket, $data, $callback = false );

	/* Get availability of data */
	public function Waiting_Data ();

	/* Write data to socket and advance buffer pointer */
	public function Write ( $length = NULL );

}


/* Write buffer base class */
abstract class Write_Buffer {

	public $socket; /* Attached socket */
	protected $data; /* Buffered data */
	protected $callback = false;

	public function __construct ( Socket $socket, $data, $callback = false )
	{
		$this->socket = $socket;
		$this->data = $data;
		$this->callback = $callback;
	}

	public function __destruct ()
	{
		if ( $this->callback ) call_user_func( $this->callback, $this->Waiting_Data() );
	}

}


class Static_Write_Buffer extends Write_Buffer implements I_Write_Buffer {

	private $pointer = 0; /* Buffered data pointer */

	/* Get availability of data */



	public function Waiting_Data ()
	{
		return isset( $this->data[$this->pointer] );
	}

	/* Write data to socket and advance buffer pointer */
	public function Write ( $length = 16384 )
	{
		$this->pointer += $this->socket->Write( substr( $this->data, $this->pointer, $length ) );
	}

}


class Stream_Write_Buffer extends Write_Buffer implements I_Write_Buffer {

	/* Get availability of data from stream */
	public function Waiting_Data ()
	{
		return ! @feof( $this->data );
	}

	public function Write ( $length = 16384 )
	{
		return $this->socket->Write_From_Stream( $this->data, $length );
	}

}

abstract class Handler {

	public $socket;

	public function Set_Option ( $wrapper, $opt, $val )
	{
		return $this->socket->Set_Option( $wrapper, $opt, $val );
	}

}

abstract class Datagram_Handler extends Handler {

	public $active = false;

	public function __construct ( $addr )
	{
		$this->socket = new Server_Socket( $addr );
	}

	public function Activate ()
	{
		try {
			if ( $ret = $this->socket->Listen( true ) ) $this->active = true;
			return $ret;
		} catch ( Server_Exception $e ) {
			throw new Server_Exception( $e->getMessage(), $e->getCode(), $e->addr, $this );
		}
	}

	public function Deactivate ( $close_socket = true )
	{
		if ( $close_socket ) {
			$this->socket->Close();
		}

		$this->active = false;
	}

	public function Write ( $to, $data )
	{
		return $this->socket->Write_To( $to, $data );
	}

	public function on_Read ( $from, $data )
	{}

	public function __destruct ()
	{
		$this->Deactivate();
	}

}


abstract class Connection_Handler extends Handler {

	const FAIL_CONNREFUSED = 1;
	const FAIL_TIMEOUT = 2;
	const FAIL_CRYPTO = 3;

	public function Write ( $data, $callback = false )
	{
		return Core::New_Static_Write_Buffer( $this->socket, $data, $callback );
	}

	/* Send open stream over the connection */
	public function Write_Stream ( $stream, $callback = false )
	{
		return Core::New_Stream_Write_Buffer( $this->socket, $stream, $callback );
	}

	public function Connect ( $timeout = false )
	{
		try {
			$this->socket->Connect( $timeout );
		} catch ( Client_Exception $e ) {
			Core::Free_Connection( $this );
			throw new Client_Exception( $e->getMessage(), $e->getCode(), $e->addr, $this );
		}
	}

	public function Disconnect ()
	{
		$this->socket->Close();
		Core::Free_Connection( $this );
	}

	public function on_Accept ()
	{}

	public function on_Connect ()
	{}

	/* Event called on failed connection */
	public function on_Connect_Fail ( $failcode )
	{}

	public function on_Disconnect ()
	{}

	public function on_Read ( $data )
	{}

	/* Event called before forking */
	public function on_Fork_Prepare ()
	{}

	/* Event called after forking, both on master and child processes */
	public function on_Fork_Done ()
	{}

}

class Listener {

	public $socket;
	public $handler_classname;
	public $handler_options; /* Passed as the first constructor parameter of each spawned connection handlers */
	public $active = false;
	public $forking = false; /* If set the listener will fork() a new process for each accepted connection */

	public function __construct ( $addr, $handler_classname, $handler_options = false, $forking = false )
	{
		$this->socket = new Server_Socket( $addr );
		$this->handler_classname = $handler_classname;
		$this->handler_options = $handler_options;
		$this->forking = ($forking && is_callable( "pcntl_fork" ));
	}

	public function Set_Option ( $wrapper, $opt, $val )
	{
		return $this->socket->Set_Option( $wrapper, $opt, $val );
	}

	/* Whether the listener should fork() a new process for each accepted connection */
	public function Set_Forking ( $forking = true )
	{
		if ( $forking && ! is_callable( "pcntl_fork" ) ) return false;
		$this->forking = $forking;
		return true;
	}

	public function Activate ()
	{
		try {
			if ( $ret = $this->socket->Listen() ) $this->active = true;
			return $ret;
		} catch ( Server_Exception $e ) {
			throw new Server_Exception( $e->getMessage(), $e->getCode(), $e->addr, $this );
		}
	}

	public function Deactivate ()
	{
		$this->socket->Close();
		$this->active = false;
	}

	public function __destruct ()
	{
		$this->Deactivate();
	}

}


/* Shared object class for inter-process communications */
class Shared_Object {

	public static $caller_pid;
	public $_oid; /* Shared object unique identifier */
	private $wrapped;
	public static $shared_count = 0;

	/* If $o is omited, a new StdClass object will be created and wrapped */
	public function __construct ( $o = false )
	{
		if ( $o === false ) $o = new StdClass();
		$this->_oid = ++ self::$shared_count;
		$this->wrapped = $o;
	}

	public function __get ( $k )
	{
		if ( Core::$child_process ) {
			return Core::$master_pipe->Ask_Master( array (
				"oid" => $this->_oid,
				"action" => "G",
				"var" => $k ) );
		} else {
			return $this->wrapped->$k;
		}
	}

	public function __set ( $k, $v )
	{
		if ( Core::$child_process ) {
			Core::$master_pipe->Ask_Master( array (
				"oid" => $this->_oid,
				"action" => "S",
				"var" => $k,
				"val" => $v ), false );
		} else {
			$this->wrapped->$k = $v;
		}
	}

	public function __call ( $m, $a )
	{
		if ( Core::$child_process ) {
			return Core::$master_pipe->Ask_Master( array (
				"oid" => $this->_oid,
				"action" => "C",
				"func" => $m,
				"args" => $a ) );
		} else {
			return call_user_func_array( array ( $this->wrapped, $m ), $a );
		}
	}

}


/* Server / multiplexer class */
final class Core {

	const VERSION = "0.01";
	private static $listeners = array (); /* Registered listeners */
	private static $write_buffers = array ();
	private static $connections = array ();
	private static $dgram_handlers = array ();
	private static $shared_objects = array ();
	private static $forked_pipes = array ();
	private static $timers = array ();
	private static $timers_updated = false;
	public static $nb_forked_processes = 0;
	public static $max_forked_processes = 64; /* Maximum number of active children
																	   before incoming connections get delayed */
	public static $child_process = false;
	private static $forked_connection; /* Forked server handled connection */
	public static $master_pipe; /* Forked server pipe to the master process */

	/* Class Flake should not be instanciated but used statically */

	private function __construct ()
	{}

	/* Register a new listener. For consistency New_Listener() will also wrap
	   Core::New_Datagram_Handler() if the given addr is of type "udp" */
	static public function New_Listener ( $addr, $handler_classname, $handler_options = false )
	{
		if ( strtolower( strtok( $addr, ":" ) ) == "udp" ) {
			$l = self::New_Datagram_Handler( $addr, $handler_classname );
		} else {
			$l = new Listener( $addr, $handler_classname, $handler_options );
			self::$listeners[] = $l;
		}

		return $l;
	}


	/* Deactivate and free a previously registered listener. For consistency Free_Listener()
	   will also wrap Core::Free_Datagram_Handler() if the given object is an instance of Datagram_Handler */
	static public function Free_Listener ( $l )
	{
		if ( $l instanceof Listener ) {
			foreach ( self::$listeners as $k => $v )
				if ( $v === $l ) {
					unset( self::$listeners[$k] );
					return true;
				}
		} else if ( $l instanceof Datagram_Handler ) {
			return self::Free_Datagram_Handler( $l );
		}

		return false;
	}


	/* Register a new static write buffer. This method is used by Connection_Handler::Write()
	   and should not be called unless you really know what you are doing. */
	static public function New_Static_Write_Buffer ( Socket $socket, $data, $callback = false )
	{
		$wb = new Static_Write_Buffer( $socket, $data, $callback );
		$wb->Write();

		if ( $wb->Waiting_Data() ) {
			self::$write_buffers[$socket->id][] = $wb;
		}

		return $wb;
	}


	/* This method is used by Connection_Handler::Write_Stream() and should not be
	   called unless you really know what you are doing. */
	static public function New_Stream_Write_Buffer ( Socket $socket, $data, $callback = false )
	{
		$wb = new Stream_Write_Buffer( $socket, $data, $callback );
		$wb->Write();

		if ( $wb->Waiting_Data() ) {
			self::$write_buffers[$socket->id][] = $wb;
		}

		return $wb;

	}

	static public function Free_Write_Buffers ( $sid )
	{
		unset( self::$write_buffers[$sid] );
	}

	static public function New_Connection ( $addr, $handler_classname, $handler_options = false )
	{
		$sck = new Client_Socket( $addr );
		$h = new $handler_classname( $handler_options );
		$h->socket = $sck;
		self::$connections[$sck->id] = $h;

		return $h;
	}

	static public function Free_Connection ( Connection_Handler $h )
	{
		$so = $h->socket;
		unset( self::$connections[$so->id] );
		self::Free_Write_Buffers( $so->id );
		$so->pending_connect = $so->pending_crypto = $so->connected = false;
		if ( self::$child_process && (self::$forked_connection === $h) ) exit();

		return true;
	}

	static public function New_Datagram_Handler ( $addr, $handler_classname )
	{
		$h = new $handler_classname( $addr );
		self::$dgram_handlers[$h->socket->id] = $h;

		return $h;
	}

	static public function Free_Datagram_Handler ( Datagram_Handler $h )
	{
		unset( self::$dgram_handlers[$h->socket->id] );
		return true;
	}

	/* Register a new shared object. Shared objects allow forked processes to use objects stored on the master process
	   if $o is ommited, a new StdClass empty object is created. */
	static public function New_Shared_Object ( $o = false )
	{
		$shr = new Shared_Object( $o );
		self::$shared_objects[$shr->_oid] = $shr;
		return $shr;
	}

	static public function Free_Shared_Object ( Shared_Object $o )
	{
		unset( self::$shared_objects[$o->_oid] );
	}

	static public function New_Timer ( $delay, $callback )
	{
		$t = new Timer( microtime( true ) + $delay, $callback );
		self::$timers[] = $t;
		self::$timers_updated = true;

		return $t;
	}

	static public function Clear_Timers ()
	{
		$ret = count( self::$timers );
		self::$timers = array ();
		return $ret;
	}

	/* Get all registered Connection_Handler objects.
	   Note: connections created by fork()ing listeners can not be retreived this way. */
	static public function Get_Connections ( $include_pending_connect = false )
	{
		$ret = array ();

		foreach ( self::$connections as $c )
			if ( $c->socket->connected || $include_pending_connect ) $ret[] = $c;

		return $ret;
	}

	/* Get all registered Listener objects */
	static public function Get_Listeners ( $include_inactive = false )
	{
		$ret = array ();

		foreach ( self::$listeners as $l )
			if ( $l->active || $include_inactive ) $ret[] = $l;

		return $ret;
	}



	/* Get all registered Timer objects. */
	static public function Get_Timers ( $include_inactive = false )
	{
		$ret = array ();

		foreach ( self::$timers as $t )
			if ( $t->active || $include_inactive ) $ret[] = $t;

		return $ret;
	}

	/* Set the maximum number of allowed children processes before delaying incoming connections.
	   Note: this setting only affect and applies to forking listeners. */
	static public function Set_Max_Children ( $i )
	{
		self::$max_forked_processes = $i;
	}

	static public function Flush_Write_Buffers ()
	{
		while ( self::$write_buffers ) {
			self::Run( 1 );
		}
	}

	/* Fork and setup IPC sockets */
	static public function Fork ()
	{
		if ( $has_shared = (Shared_Object::$shared_count > 0) ) {
			list ( $s1, $s2 ) = IPC_Socket::Pair();
		}

		$pid = pcntl_fork();

		if ( $pid === 0 ) {
			self::$child_process = true;

			if ( $has_shared ) {
				self::$master_pipe = $s2;
			}
		} else if ( $pid > 0 ) {
			++ self::$nb_forked_processes;

			if ( $has_shared ) {
				$s1->pid = $pid;
				self::$forked_pipes[$pid] = $s1;
			}
		}

		return $pid;
	}

	/* The <var>$time</var> parameter can have different meanings:
	   <ul>
	   <li>int or float > 0 : the main loop will run once and will wait for activity for a maximum of <var>$time</var> seconds</li>
	   <li>0 : the main loop will run once and will not wait for activity when polling, only handling waiting packets and timers</li>
	   <li>int or float < 0 : the main loop will run for -<var>$time</var> seconds exactly, whatever may happen</li>
	   <li>NULL : the main loop will run forever</li>
	   </ul>

	   float   $time         how much time should we run, if omited flake will enter an endless loop
	   array   $user_streams if specified, user streams will be polled along with internal streams
	   array the user streams with pending data
	 */
	static public function Run ( $time = NULL, array $user_streams = NULL )
	{
		$tmp = 0;
		$ret = array ();

		if ( isset( $time ) ) {
			if ( $time < 0 ) {
				$poll_max_wait = - $time;
				$exit_mt = microtime( true ) - $time;
			} else {
				$poll_max_wait = $time;
				$exit = true;
			}
		} else {
			$poll_max_wait = 60;
			$exit = false;
		}

		do {
			$t = microtime( true );

			/* Timers */
			if ( self::$timers_updated ) {
				usort( self::$timers, function  ( Timer $a, Timer $b )
				{
					return $a->microtime > $b->microtime;
				} );
				self::$timers_updated = false;
			}

			$next_timer_md = NULL;

			if ( self::$timers ) foreach ( self::$timers as $k => $tmr ) {
				if ( $tmr->microtime > $t ) {
					$next_timer_md = $tmr->microtime - $t;
					break;
				} else if ( $tmr->active ) {
					$tmr->Deactivate();
					call_user_func( $tmr->callback );
				}

				unset( self::$timers[$k] );
			}

			if ( self::$timers_updated ) {
				$t = microtime( true );
				usort( self::$timers, function  ( Timer $a, Timer $b )
				{
					return $a->microtime > $b->microtime;
				} );

				foreach ( self::$timers as $tmr ) {
					if ( $tmr->microtime > $t ) {
						$next_timer_md = $tmr->microtime - $t;
						break;
					}
				}

				self::$timers_updated = false;
			}

			/* Write buffers to non blocked sockets. */
			foreach ( self::$write_buffers as $write_buffers ) {
				if ( ! $write_buffers || $write_buffers[0]->socket->blocked || ! $write_buffers[0]->socket->connected ) continue;

				foreach ( $write_buffers as $wb ) {
					while ( $wb->Waiting_Data() && ! $wb->socket->blocked ) {
						$wb->Write();
						if ( ! $wb->Waiting_Data() ) {
							array_shift( self::$write_buffers[$wb->socket->id] );
							if ( ! self::$write_buffers[$wb->socket->id] ) self::Free_Write_Buffers( $wb->socket->id );
							break;
						}
					}
				}
			}

			$handler = $so = $write_buffers = $l = $c = $wbs = $wb = $data = $so = NULL;

			/* Prepare socket arrays. */
			$fd_lookup_r = $fd_lookup_w = $rfd = $wfd = $efd = array ();

			foreach ( self::$listeners as $l )
				if ( ($l->active) && ((! $l->forking) || (self::$nb_forked_processes <= self::$max_forked_processes)) ) {
					$fd = $l->socket->fd;
					$rfd[] = $fd;
					$fd_lookup_r[( int ) $fd] = $l;
				}

			$next_conn_timeout_mt = NULL;

			foreach ( self::$connections as $c ) {
				$so = $c->socket;

				if ( $so->pending_crypto ) {
					$cr = $so->Enable_Crypto();

					if ( $cr === true ) {
						$c->on_Accept();
					} else if ( $cr === false ) {
						$c->on_Connect_Fail( Connection_Handler::FAIL_CRYPTO );
						self::Free_Connection( $c );
					} else {
						$fd = $so->fd;
						$rfd[] = $fd;
						$fd_lookup_r[( int ) $fd] = $c;
					}
				} else if ( $so->connected ) {
					if ( ! $so->block_reads ) {
						$fd = $so->fd;
						$rfd[] = $fd;
						$fd_lookup_r[( int ) $fd] = $c;
					}
				} else if ( $so->connect_timeout < $t ) {
					$c->on_Connect_Fail( Connection_Handler::FAIL_TIMEOUT );
					self::Free_Connection( $c );
				} else if ( $so->pending_connect ) {
					$fd = $so->fd;
					$wfd[] = $fd;
					$fd_lookup_w[( int ) $fd] = $c;

					if ( ! $next_conn_timeout_mt || ($sc->connect_timeout < $next_conn_timeout_mt) ) {
						$next_conn_timeout_mt = $sc->connect_timeout;
					}
				}
			}

			if ( self::$dgram_handlers ) foreach ( self::$dgram_handlers as $l )
				if ( $l->active ) {
					$fd = $l->socket->fd;
					$rfd[] = $fd;
					$fd_lookup_r[( int ) $fd] = $l;
				}

			foreach ( self::$write_buffers as $wbs )
				if ( $wbs[0]->socket->blocked ) {
					$fd = $wbs[0]->socket->fd;
					$wfd[] = $fd;
					$fd_lookup_w[( int ) $fd] = self::$connections[$wbs[0]->socket->id];
				}

			if ( self::$forked_pipes ) foreach ( self::$forked_pipes as $fp ) {
				$fd = $fp->fd;
				$rfd[] = $fd;
				$fd_lookup_r[( int ) $fd] = $fp;
			}

			if ( isset( $user_streams ) ) {
				foreach ( ( array ) $user_streams[0] as $tmp_r )
					$rfd[] = $tmp_r;
				foreach ( ( array ) $user_streams[1] as $tmp_w )
					$wfd[] = $tmp_w;
			}


			/* Main select */
			$wait_mds = array ( $poll_max_wait );
			if ( isset( $next_timer_md ) ) $wait_mds[] = $next_timer_md;
			if ( isset( $exit_mt ) ) $wait_mds[] = $exit_mt - $t;
			if ( isset( $next_conn_timeout_mt ) ) $wait_mds[] = $next_conn_timeout_mt - $t;

			$wait_md = min( $wait_mds );

			$tv_sec = ( int ) $wait_md;
			$tv_usec = ($wait_md - $tv_sec) * 1000000;

			if ( ($rfd || $wfd) && (@stream_select( $rfd, $wfd, $efd, $tv_sec, $tv_usec )) ) {
				foreach ( $rfd as $act_rfd ) {
					$handler = $fd_lookup_r[( int ) $act_rfd];
					$so = $handler->socket;

					if ( $handler instanceof Connection_Handler ) {
						if ( $so->pending_crypto ) {
							$cr = $so->Enable_Crypto();

							if ( $cr === true ) {
								$handler->on_Accept();
							} else if ( $cr === false ) {
								$handler->on_Connect_Fail( Connection_Handler::FAIL_CRYPTO );
								self::Free_Connection( $handler );
							}
						} else if ( ! $so->connected ) {
							continue;
						}

						$data = $so->Read();

						if ( ($data === "") || ($data === false) ) {
							if ( $so->Eof() ) {
								/* Disconnected socket. */
								$handler->on_Disconnect();
								self::Free_Connection( $handler );
							}
						} else {
							/* Data available. */
							$handler->on_Read( $data );
						}
					} else if ( $handler instanceof Datagram_Handler ) {
						$from = "";
						$data = $so->Read_From( $from );
						$handler->on_Read( $from, $data );
					} else if ( $handler instanceof Listener ) {
						while ( $fd = $so->Accept() ) {

							/* New connection accepted. */
							$sck = new Socket( $fd, $so->crypto_type );
							$hnd = new $handler->handler_classname( $handler->handler_options );
							$hnd->socket = $sck;

							if ( $handler->forking ) {
								$hnd->on_Fork_Prepare();

								if ( self::Fork() === 0 ) {
									$hnd->on_Fork_Done();

									self::$write_buffers = self::$listeners = array ();
									self::$connections = array (
										$sck->id => $hnd );
									self::$forked_connection = $hnd;
									self::Clear_Timers();

									if ( $sck->Setup() ) {
										$hnd->on_Accept();
									}

									$handler = $hnd = $sck = $l = $c = $wbs = $wb = $fd_lookup_r = $fd_lookup_w = false;

									break;
								}

								$hnd->on_Fork_Done();

								if ( self::$nb_forked_processes >= self::$max_forked_processes ) break;
							} else {
								self::$connections[$sck->id] = $hnd;

								if ( $sck->Setup() ) {
									$hnd->on_Accept();
								}
							}

							$sck = $hnd = NULL;
						}
					} else if ( $handler instanceof IPC_Socket ) {

						while ( $ipcm = $handler->Read() ) {
							if ( (! $ipcq = unserialize( $ipcm )) || (! is_object( $o = self::$shared_objects[$ipcq["oid"]] )) ) continue;

							switch ( $ipcq["action"] ) {
							case "G" :
								$handler->Write( serialize( $o->$ipcq["var"] ) );
								break;

							case "S" :
								$o->$ipcq["var"] = $ipcq["val"];
								break;

							case "C" :
								Shared_Object::$caller_pid = $handler->pid;
								$handler->Write( serialize( call_user_func_array( array (
									$o,
									$ipcq["func"] ), $ipcq["args"] ) ) );
								break;

							}
						}

						$o = $ipcq = $ipcm = NULL;
					} else if ( ! isset( $handler ) ) {
						/* User stream. */
						$ret[0][] = $act_rfd;
					}
				}

				foreach ( $wfd as $act_wfd ) {
					$handler = $fd_lookup_w[$act_wfd];
					$so = $handler->socket;

					if ( ! isset( $handler ) ) {
						/* User stream. */
						$ret[1][] = $act_wfd;
					} else if ( $so->connected ) {
						/* Unblock buffered write. */
						if ( $so->Eof() ) {
							$handler->on_Disconnect();
							self::Free_Connection( $handler );
						} else {
							$so->blocked = false;
						}
					} else if ( $so->pending_connect ) {
						/* Pending connect. */
						if ( $so->Eof() ) {
							$handler->on_Connect_Fail( Connection_Handler::FAIL_CONNREFUSED );
							self::Free_Connection( $handler );
						} else {
							$so->Setup();
							$so->connected = true;
							$so->pending_connect = false;
							$handler->on_Connect();
						}
					}
				}
			}

			if ( self::$nb_forked_processes && ! self::$child_process ) while ( (($pid = pcntl_wait( $tmp, WNOHANG )) > 0) && self::$nb_forked_processes -- )
				unset( self::$forked_pipes[$pid] );

			if ( $ret ) {
				return $ret;
			} else if ( isset( $exit_mt ) ) {
				$exit = $exit_mt <= $t;
			}
		} while ( ! $exit );
	}

}

?>