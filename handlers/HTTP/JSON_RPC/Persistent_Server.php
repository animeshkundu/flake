<?php

namespace Flake\HTTP\JSON_RPC;

require_once "flake/handlers/HTTP/JSON_RPC/Server.php";

/* Persistent JSON-RPC server class. */
class Persistent_Server extends \Flake\HTTP\JSON_RPC\Server {
	
	private $wrapped;

	public function __construct ( $o )
	{
		$this->wrapped = $o;
	}

	final public function on_Call ( $method, $args )
	{
		return call_user_func_array( array ( $this->wrapped, $method ), $args );
	}

}

?>