<?php

namespace Flake\HTTP;

require_once "flake/flake.php";

/* HTTP Service handler class */
abstract class Server extends \Flake\Connection_Handler {

	const SERVER_STRING = "";
	const MAX_REQUEST_LENGTH = 1048576;
	const DEFAULT_CONTENT_TYPE = "text/html";
	const COMPRESS_AUTO = 1;
	const COMPRESS_OFF = 2;

	/* Response status codes and strings */
	private $STATUS_CODES = array (
		100 => "100 Continue",
		200 => "OK",
		201 => "Created",
		204 => "No Content",
		206 => "Partial Content",
		300 => "Multiple Choices",
		301 => "Moved Permanently",
		302 => "Found",
		303 => "See Other",
		304 => "Not Modified",
		307 => "Temporary Redirect",
		400 => "Bad Request",
		401 => "Unauthorized",
		403 => "Forbidden",
		404 => "Not Found",
		405 => "Method Not Allowed",
		406 => "Not Acceptable",
		408 => "Request Timeout",
		410 => "Gone",
		413 => "Request Entity Too Large",
		414 => "Request URI Too Long",
		415 => "Unsupported Media Type",
		416 => "Requested Range Not Satisfiable",
		417 => "Expectation Failed",
		500 => "Internal Server Error",
		501 => "Method Not Implemented",
		503 => "Service Unavailable",
		506 => "Variant Also Negotiates" );

	protected $request_headers = array ();
	protected $request_method = "";
	protected $request_protocol = "";
	protected $request_content = "";
	protected $request_url = "";
	protected $compress = self::COMPRESS_AUTO;
	protected $compress_level = 9;
	private $request_buffer = "";
	private $response_headers = array ();
	private $response_content_type;
	private $response_status = 200;

	public function __construct ()
	{
		$this->response_content_type = static::DEFAULT_CONTENT_TYPE;
	}

	protected function Handle_Request ( $url )
	{
		$this->request_url = $url;
		$data = $this->on_Request( $url );
		$this->Send_Response( $data );
	}

	private function parse ( $headers, $url, $cookies = array() )
	{
		if ( empty( $url ) || empty( $headers ) ) return false;

		$output = parse_url( trim( $url ) );

		if ( empty( $output['path'] ) ) $output['path'] = '/';

		foreach ( $headers as $header ) {

			if ( preg_match( '/^Cookie:/i', $header ) ) {

				$header = preg_replace( '/^Cookie:/i', '', trim( $header ) );
				$elements = explode( ';', $header );
				$array = array ( 'secure' => 0 );

				foreach ( $elements as $element ) {

					list ( $name, $content ) = explode( '=', trim( $element ), 2 );
					$name = trim( $name );

					if ( ! empty( $name ) ) {
						switch ( $name ) {
						case 'domain' :
						case 'path' :
						case 'comment' :
							$array[$name] = trim( $content );
							break;

						case 'expires' :
							$array['expires'] = trim( $content );
							break;

						case 'secure' :
							$array['secure'] = 1;
							break;

						default :
							$cookie_name = $name;
							$array[$name] = trim( $content );
							break;
						}
					}
				}

				if ( empty( $array['domain'] ) ) $array['domain'] = $output['host'];

				if ( empty( $array['path'] ) ) $array['path'] = $output['path'];

				$cookies[$array['domain']][$array['path']][$cookie_name] = $array;
			}
		}

		return $cookies;
	}

	final public function mime_type ( $filename )
	{
		$mime_types = array (
			'txt' => 'text/plain',
			'htm' => 'text/html',
			'html' => 'text/html',
			'php' => 'text/html',
			'css' => 'text/css',
			'js' => 'application/javascript',
			'json' => 'application/json',
			'xml' => 'application/xml',
			'swf' => 'application/x-shockwave-flash',
			'flv' => 'video/x-flv',

			'png' => 'image/png',
			'jpe' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'gif' => 'image/gif',
			'bmp' => 'image/bmp',
			'ico' => 'image/vnd.microsoft.icon',
			'tiff' => 'image/tiff',
			'tif' => 'image/tiff',
			'svg' => 'image/svg+xml',
			'svgz' => 'image/svg+xml',

			'zip' => 'application/zip',
			'rar' => 'application/x-rar-compressed',
			'exe' => 'application/x-msdownload',
			'msi' => 'application/x-msdownload',
			'cab' => 'application/vnd.ms-cab-compressed',

			'mp3' => 'audio/mpeg',
			'qt' => 'video/quicktime',
			'mov' => 'video/quicktime',

			'pdf' => 'application/pdf',
			'psd' => 'image/vnd.adobe.photoshop',
			'ai' => 'application/postscript',
			'eps' => 'application/postscript',
			'ps' => 'application/postscript',

			'doc' => 'application/msword',
			'rtf' => 'application/rtf',
			'xls' => 'application/vnd.ms-excel',
			'ppt' => 'application/vnd.ms-powerpoint',

			'odt' => 'application/vnd.oasis.opendocument.text',
			'ods' => 'application/vnd.oasis.opendocument.spreadsheet' );

		$ext = strtolower( array_pop( explode( '.', $filename ) ) );

		if ( array_key_exists( $ext, $mime_types ) ) {
			return $mime_types[$ext];
		} /*elseif ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME );
			$mimetype = finfo_file( $finfo, $filename );
			finfo_close( $finfo );
			return $mimetype;
		}*/ else {
			return 'application/octet-stream';
		}
	}

	final public function Populate_Globals ()
	{
		$_GET = $_POST = $_FILES = $_COOKIE = $_SERVER = $_REQUEST = $_SESSION = array ();

		$str = explode( '?', $this->request_url );

		/* Populate $_SERVER. */
		$_SERVER = $this->request_headers;

		$http = explode( '/', $this->request_protocol );
		if ( $http[0] === "https" ) $_SERVER['HTTPS'] = 1;

		$_SERVER['REQUEST_URI'] = substr( $str[0], 1 );
		$_SERVER['QUERY_STRING'] = count( $str ) > 1 ? $str[1] : '';
		$_SERVER['REQUEST_TIME'] = time();

		/* Populate $_COOKIES. */
		$_COOKIE = $this->parse( $this->request_headers, $str[0] );

		if ( ! empty( $str[1] ) ) parse_str( $str[1], $_GET );

		if ( ! isset( $this->request_content['CONTENT-TYPE'] ) || empty( $this->request_content['CONTENT-TYPE'] ) ) {
			parse_str( urldecode( $this->request_content ), $_POST );
			$_REQUEST = array_merge( $_POST, $_GET );
			return;
		}

		preg_match( '/boundary=(.*)$/', $this->request_headers['CONTENT-TYPE'], $matches );

		/* Content Type is probably regular form-encoded. */
		if ( count( $matches ) <= 1 ) {
			parse_str( urldecode( $this->request_content ), $_POST );
		} else {
			$boundary = $matches[1];
			$p_data = preg_split( "/-+$boundary/", $this->request_content );
			array_pop( $p_data );

			foreach ( $p_data as $id => $block ) {
				if ( empty( $block ) ) continue;

				if ( strpos( $block, 'application/octet-stream' ) !== FALSE ) {
					if ( preg_match( "/name=\"([^\"]*)\".*filename=\"([^\"]*)\".*?Content-Type:(.*?)[\n|\r|\r\n]{2}+([^\n\r].*)?$/s", $block, $matches ) ) {
						$_FILES[$matches[1]]['name'] = $matches[2];
						$_FILES[$matches[1]]['type'] = $matches[3];
						$_FILES[$matches[1]]['data'] = $matches[4];
						$_FILES[$matches[1]]['size'] = strlen( $matches[4] );
						$_POST[$matches[1]] = $matches[2];
					}
				} else {
					if ( preg_match( '/name=\"([^\"]*)\".*filename=\"([^\"]*)\".*?Content-Type:(.*?)[\n|\r|\r\n]{2}+([^\n\r].*)?$/s', $block, $matches ) ) {
						$_FILES[$matches[1]]['name'] = $matches[2];
						$_FILES[$matches[1]]['type'] = $matches[3];
						$_FILES[$matches[1]]['data'] = $matches[4];
						$_FILES[$matches[1]]['size'] = strlen( $matches[4] );
						$_POST[$matches[1]] = $matches[2];
					} else if ( preg_match( '/name=\"([^\"]*)\"[\n|\r|\r\n]+?([^\r\n]+)[\n|\r|\r\n]?$/s', $block, $matches ) ) {
						$_POST[$matches[1]] = $matches[2];
					}
				}
			}
		}

		$_REQUEST = array_merge( $_POST, $_GET );
	}

	final public function on_Read ( $data )
	{
		$this->data = $data;
		$this->request_buffer .= $data;

		while ( $this->request_buffer ) {
			if ( ($p = strpos( $this->request_buffer, "\r\n\r\n" )) !== false ) {
				$hdrs = substr( $this->request_buffer, 0, $p );
				$cnt = substr( $this->request_buffer, $p + 4 );
			} else if ( ($p = strpos( $this->request_buffer, "\n\n" )) !== false ) {
				$hdrs = substr( $this->request_buffer, 0, $p );
				$cnt = substr( $this->request_buffer, $p + 2 );
			} else {
				if ( isset( $this->request_buffer[static::MAX_REQUEST_LENGTH] ) ) {
					$this->Set_Response_Status( 414 );
					$this->Send_Response( "Request too large" );
				} else {
					return;
				}
			}

			/* Extract first line. */
			list ( $this->request_method, $url, $this->request_protocol ) = explode( " ", strtok( $hdrs, "\r\n" ) );

			/* Process headers. */
			$hdrs = explode( "\n", trim( strtok( "" ) ) );
			$tmp = array ();

			foreach ( $hdrs as $hdr ) {
				$k = strtoupper( strtok( $hdr, ":" ) );
				$tmp[$k] = trim( strtok( "" ) );
			}

			$this->request_headers = $tmp;

			if ( (isset( $this->request_headers["CONTENT-LENGTH"] )) && ($cl = $this->request_headers["CONTENT-LENGTH"]) ) {
				if ( $cl > static::MAX_REQUEST_LENGTH ) {
					$this->Set_Response_Status( 413 );
					$this->Send_Response( "Request too large" );
				} else if ( strlen( $cnt ) < $cl ) {
					return;
				}

				$this->request_content = substr( $cnt, 0, $cl );
				$this->request_buffer = ltrim( substr( $cnt, $cl ) );
			} else {
				$this->request_content = "";
				$this->request_buffer = $cnt;
			}

			if ( ($this->request_protocol !== "HTTP/1.0") && ($this->request_protocol !== "HTTP/1.1") ) {
				$this->Set_Response_Status( 400 );
				$this->Send_Response( "Bad Request" );
			} else {
				$this->Handle_Request( $url );
			}
		}
	}

	/* The string returned by on_Request() will be sent back as the HTTP response. */
	abstract public function on_Request ( $url );

	public function Add_Header ( $header )
	{
		$this->response_headers[] = $header;
	}

	public function Set_Content_Type ( $content_type )
	{
		$this->response_content_type = $content_type;
	}

	public function Set_Response_Status ( $code = 200 )
	{
		$this->response_status = $code;
	}

	public function Set_Compression ( $opt = self::COMPRESS_AUTO )
	{
		switch ( $opt ) {
		case self::COMPRESS_AUTO :
			$this->compress = extension_loaded( "zlib" ) ? self::COMPRESS_AUTO : self::COMPRESS_OFF;
			break;
		case self::COMPRESS_OFF :
			$this->compress = $opt;
			break;
		default :
			throw new \Flake\Exception( "invalid compress option '{$opt}'" );
		}
	}

	/* Compress a response if possible and needed. */
	protected function Compress_Response ( &$data, &$encoding = NULL )
	{
		$methods = array (
			"deflate" => "gzdeflate",
			"gzip" => "gzencode",
			"compress" => "gzcompress" );

		foreach ( $methods as $m => $func ) {
			if ( isset( $this->request_headers["ACCEPT-ENCODING"] ) && strpos( $this->request_headers["ACCEPT-ENCODING"], $m ) !== false ) {
				$method = $m;
				break;
			}
		}

		if ( ! isset( $method ) ) return false;

		$data = $func( $data, $this->compress_level );
		$encoding = $method;

		return true;
	}

	/* This method is only invoked by the on_Read() handler. */
	protected function Send_Response ( $data, $length = null )
	{
		$keep = isset( $this->request_headers["CONNECTION"] ) ? (strtoupper( $this->request_headers["CONNECTION"] ) === "KEEP-ALIVE") : false;

		if ( ($this->compress !== self::COMPRESS_OFF) && $this->Compress_Response( $data, $encoding ) )
			$compress = "Content-Encoding: " . $encoding . "\r\n";
		else
			$compress = '';

		$resp = "HTTP/1.1 " . ( int ) $this->response_status . " " . $this->STATUS_CODES[$this->response_status] . "\r\n" . "Date: " . gmdate( "D, d M Y H:i:s T" ) . "\r\n" . "Server: " . (static::SERVER_STRING ? static::SERVER_STRING : "flake/0.01") . "\r\n" . "Content-Type: " . $this->response_content_type . "\r\n" . "Content-Length: " . (isset( $length ) ? $length : strlen( $data )) . "\r\n" . "Connection: " . ($keep ? "Keep-Alive" : "Close") . "\r\n" . $compress;

		if ( $this->response_headers ) $resp .= implode( "\r\n", $this->response_headers ) . "\r\n";

		$resp .= "\r\n" . $data;

		/* We don't need keepalives for payu site. */
		$this->Write( $resp, ($keep ? false : array ( $this, "Disconnect" )) );

		$this->response_content_type = static::DEFAULT_CONTENT_TYPE;
		$this->response_headers = array ();
		$this->response_status = 200;
	}

}

/* HTTP Asynchronous service handler class. */
abstract class Async_Server extends Server {

	protected function Handle_Request ( $url )
	{
		$this->request_url = $url;
		$this->on_Request( $url );
	}

}