<?php

namespace Flake\HTTP\SOAP;

require_once "flake/handlers/HTTP/SOAP/Server.php";

/* Persistent SOAP over HTTP service handler class. */
class Persistent_Server extends \Flake\HTTP\SOAP\Server {
	
	private $wrapped;

	public function __construct ( $o, $soap_options = false )
	{
		$this->wrapped = $o;
		
		if ( $soap_options === false ) {
			parent::__construct( $o );
		} else {
			parent::__construct( $o, $soap_options );
		}
	}

	public function Get_Exports ()
	{
		$ret = array ();
		$rc = new \ReflectionClass( get_class( $this->wrapped ) );
		
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

	final public function on_Call ( $method, $args )
	{
		return call_user_func_array( array ( $this->wrapped, $method ), $args );
	}

}

?>