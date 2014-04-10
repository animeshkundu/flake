<?php

namespace Flake\HTTP\JSON_RPC;

require_once "flake/handlers/HTTP/JSON_RPC/Server.php";

/* If you extend this handler, your methods will be publicly callable by the name they have in PHP.*/
abstract class Direct_Server extends \Flake\HTTP\JSON_RPC\Server {

	final public function on_Call ( $method, $args )
	{
		if ( is_callable( array ( $this, $method ) ) ) return call_user_func_array( array ( 
			$this, 
			$method ), $args );
	}

}

?>