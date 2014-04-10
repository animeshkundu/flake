<?php

namespace Flake\HTTP\SOAP;

require_once "flake/handlers/HTTP/SOAP/Server.php";

/* If you extend this handler, your methods will be publicly callable by the name they have in PHP. */
abstract class Direct_Server extends \Flake\HTTP\SOAP\Server {

	final public function on_Call ( $method, $args )
	{
		if ( is_callable( array ( $this, $method ) ) ) return call_user_func_array( array ( 
			$this, 
			$method ), $args );
	}

	public function Get_Exports ()
	{
		$ret = array ();
		$rc = new \ReflectionClass( get_class( $this ) );
		
		foreach ( $rc->getMethods() as $rm ) {
			if ( (! $rm->isPublic()) || ($rm->getDeclaringClass() != $rc) ) continue;
			$params = array ();
			
			foreach ( $rm->getParameters() as $rp ) {
				$params[] = array ( "name" => $rp->getName() );
			}
			
			$ret[$rm->getName()] = $params;
		}
		
		return $ret;
	}

}

?>