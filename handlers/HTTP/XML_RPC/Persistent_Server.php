<?php

namespace Flake\HTTP\XML_RPC;

require_once "flake/handlers/HTTP/XML_RPC/Server.php";

class Persistent_Server extends \Flake\HTTP\XML_RPC\Server {
	
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