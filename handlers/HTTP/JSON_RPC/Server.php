<?php

namespace Flake\HTTP\JSON_RPC;

require_once "flake/handlers/HTTP/Server.php";


abstract class Server extends \Flake\HTTP\Server {
	
	protected $request_url = "";

	final public function on_Request ( $url )
	{
		$this->request_url = $url;
		$req = json_decode( $this->request_content );
		$ret = array ( "id" => $req->id );
		
		if ( $req === NULL ) {
			$this->Set_Response_Status( 400 );
			
			switch ( json_last_error() ) {
			case JSON_ERROR_DEPTH :
				$ret["error"] = "The maximum stack depth has been exceeded";
				break;
			case JSON_ERROR_CTRL_CHAR :
				$ret["error"] = "Control character error, possibly incorrectly encoded";
				break;
			case JSON_ERROR_SYNTAX :
				$ret["error"] = "Syntax error";
				break;
			default :
				$ret["error"] = "Unknown error";
				break;
			}
			
			return json_encode( $ret );
		}
		
		try {
			$ret["result"] = $this->on_Call( $req->method, $req->params );
		} catch ( \Exception $e ) {
			$ret["error"] = $e->getMessage();
		}
		
		if ( isset( $req->id ) ) {
			return json_encode( $ret );
		} else {
			return "";
		}
	}
	
	/* The value returned by on_Call() will be sent back as the JSON-RPC method call response. */
	abstract public function on_Call ( $method, $args );

}

?>