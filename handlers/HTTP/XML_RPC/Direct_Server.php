<?php

namespace Flake\HTTP\XML_RPC;

require_once "flake/handlers/HTTP/XML_RPC/Server.php";

/* If you extend this handler, your methods will be publicly callable by the name they have in PHP. */
abstract class Direct_Server extends \Flake\HTTP\XML_RPC\Server {

	final public function on_Call ( $method, $args )
	{
		if ( ! is_callable( array ( $this, $method ) ) ) {
			throw new \Exception( "invalid method: '{$method}'" );
		}
		
		return call_user_func_array( array ( $this, $method ), $args );
	}

}

?>