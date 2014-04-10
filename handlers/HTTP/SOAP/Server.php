<?php

namespace Flake\HTTP\SOAP;

require_once "flake/handlers/HTTP/Server.php";

/* SOAP 1.1 over HTTP Service handler class. */
abstract class Server extends \Flake\HTTP\Server {
	
	protected $request_url = "";
	protected $method_name; /* Last called method name. */
	protected $exports; /* List of exported methods with their parameters. */
	protected $hostname; /* Useful for WSDL automatic generation. */
	
	/* Only builds the exports list for WSDL automatic generation. */
	
	
	
	public function __construct ( $options )
	{
		if ( isset( $options["hostname"] ) ) {
			$this->hostname = $options["hostname"];
		}
		$this->exports = $this->Get_Exports();
	}
	
	/* This methods needs to be overloaded and return the correct list in child classes. */
	abstract public function Get_Exports ();
	
	/* Convert a PHP variable to SOAP string representation.  */
	protected function Variable_To_SOAP_String ( $var, $key = false )
	{
		if ( is_array( $var ) ) {
			$ret = "";
			
			foreach ( $var as $k => $v ) {
				if ( is_numeric( $k ) && ($key !== false) ) $k = $key . "_member";
				$ret .= "<{$k}>" . $this->Variable_To_SOAP_String( $v, $k ) . "</{$k}>";
			}
			
			return $ret;
		} else {
			return utf8_encode( $var );
		}
	}

	protected function SOAP_Add_Response_Envelope ( $result )
	{
		$this->Set_Content_Type( "text/xml" );
		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<SOAP-ENV:Envelope xmlns:SOAP-ENV=\"http://schemas.xmlsoap.org/soap/envelope/\" SOAP-ENV:encodingStyle=\"http://schemas.xmlsoap.org/soap/encoding/\"><SOAP-ENV:Body>{$result}</SOAP-ENV:Body></SOAP-ENV:Envelope>";
	}

	protected function Fault ( $string, $code = "Server" )
	{
		unset( $this->method_name );
		return $this->SOAP_Add_Response_Envelope( "<SOAP-ENV:Fault><faultcode>SOAP-ENV:{$code}</faultcode><faultstring>{$string}</faultstring></SOAP-ENV:Fault>" );
	}
	
	/* Base location for the HTTP service based on the hostname option and the listening port. */
	protected function Get_Base_Href ()
	{
		$ret = "http://";
		
		if ( $this->hostname ) {
			$ret .= $this->hostname;
			if ( substr( $sn = $this->socket->Get_Name(), - 3 ) !== ":80" ) {
				strtok( $sn, ":" );
				$ret .= ":" . strtok( "" );
			}
		} else if ( isset( $this->request_headers["HOST"] ) ) {
			$ret .= $this->request_headers["HOST"];
		} else {
			$ret .= php_uname( "n" );
			
			if ( substr( $sn = $this->socket->Get_Name(), - 3 ) !== ":80" ) {
				strtok( $sn, ":" );
				$ret .= ":" . strtok( "" );
			}
		}
		
		return $ret;
	}
	
	/* Generates a WSDL document for the current service. */
	public function Get_WSDL ()
	{
		$classname = get_class( $this );
		$base_href = $this->Get_Base_Href();
		
		$ret = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$ret .= "<definitions targetNamespace=\"urn:nssoap.{$classname}\"\n";
		$ret .= "             xmlns=\"http://schemas.xmlsoap.org/wsdl/\"\n";
		$ret .= "             xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n";
		$ret .= "             xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\"\n";
		$ret .= "             xmlns:soap=\"http://schemas.xmlsoap.org/wsdl/soap/\"\n";
		
		$i = 0;
		
		foreach ( array_keys( $this->exports ) as $mname ) {
			$ret .= "             xmlns:xsd" . (++ $i) . "=\"urn:nssoap.{$mname}_d\"\n";
		}
		
		$ret .= ">\n\n";
		$i = 0;
		
		foreach ( array_keys( $this->exports ) as $mname ) {
			$ret .= "<import namespace=\"urn:nssoap.{$mname}_d\" location=\"{$base_href}/{$mname}/xsd\" />\n\n";
			$ret .= "<message name=\"{$mname}\">\n";
			$ret .= "   <part name=\"{$mname}\" element=\"xsd" . (++ $i) . ":{$mname}\" />\n";
			$ret .= "</message>\n\n";
			$ret .= "<message name=\"{$mname}_Response\">\n";
			$ret .= "   <part name=\"{$mname}_Response\" />\n";
			$ret .= "</message>\n\n";
			$ret .= "<portType name=\"{$mname}_PortType\">\n";
			$ret .= "   <operation name=\"{$mname}\">\n";
			$ret .= "      <input message=\"{$mname}\" />\n";
			$ret .= "      <output message=\"{$mname}_Response\" />\n";
			$ret .= "   </operation>\n";
			$ret .= "</portType>\n\n";
			$ret .= "<binding name=\"{$mname}_Binding\" type=\"{$mname}_PortType\">\n";
			$ret .= "   <soap:binding transport=\"http://schemas.xmlsoap.org/soap/http\" style=\"document\"/>\n";
			$ret .= "   <operation name=\"{$mname}\">\n";
			$ret .= "       <soap:operation soapAction=\"{$mname}\" />\n";
			$ret .= "       <input><soap:body use=\"literal\" /></input>\n";
			$ret .= "       <output><soap:body use=\"literal\" /></output>\n";
			$ret .= "   </operation>\n";
			$ret .= "</binding>\n\n";
			$ret .= "<service name=\"{$mname}\">\n";
			$ret .= "   <port name=\"{$mname}_PortType\" binding=\"{$mname}_Binding\">\n";
			$ret .= "      <soap:address location=\"{$base_href}/{$mname}\" />\n";
			$ret .= "   </port>\n";
			$ret .= "</service>\n\n";
		}
		
		$ret .= "</definitions>\n";
		return $ret;
	}
	
	/* Generates a XSD document for the specified method. */
	public function Get_XSD ( $method )
	{
		$classname = get_class( $this );
		$base_href = $this->Get_Base_Href();
		
		$ret = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$ret .= "<xs:schema targetNamespace=\"urn:nssoap.{$method}_d\" xmlns=\"urn:nssoap.{$method}_d\" xmlns:xs=\"http://www.w3.org/2001/XMLSchema\">\n\n";
		$ret .= "<xs:element name=\"{$method}\">\n";
		$ret .= "    <xs:complexType>\n";
		$ret .= "       <xs:sequence>\n";
		
		foreach ( $this->exports[$method] as $param )
			$ret .= "           <xs:element ref=\"{$param['name']}\"/>\n";
		
		$ret .= "       </xs:sequence>\n";
		$ret .= "      </xs:complexType>\n";
		$ret .= "</xs:element>\n\n";
		
		foreach ( $this->exports[$method] as $param ) {
			$ret .= "<xs:element name=\"{$param['name']}\">\n";
			
			if ( $ptype = $param["type"] ) {
				$ret .= "    <xs:simpleType>\n";
				$ret .= "        <xs:restriction base=\"xs:{$ptype}\" />\n";
				$ret .= "    </xs:simpleType>\n";
			}
			
			$ret .= "</xs:element>\n";
		}
		
		$ret .= "</xs:schema>\n";
		return $ret;
	}

	final public function on_Request ( $url )
	{
		$this->request_url = $url;
		$purl = explode( "/", trim( $url, "/" ) );
		
		if ( isset( $this->exports[$umethod = $purl[0]] ) ) {
			if ( (! isset( $purl[1] )) || ($purl[1] === "call") ) {
				
				/* Method call. */
				$x = @simplexml_load_string( $this->request_content, NULL, 0, "http://schemas.xmlsoap.org/soap/envelope/" );
				
				if ( $x === false ) return $this->Fault( "unable to decode XML request" );
				
				if ( $x->Body ) {
					$xdata = $x->Body->children( "urn:nssoap." . $umethod . "_d" );
				} else {
					$xdata = $x->children( 'http://schemas.xmlsoap.org/soap/envelope/' )->Body;
				}
				
				list ( $xmethod ) = each( $xdata );
				$xargs = $xdata->children();
				$method = ( string ) $xmethod;
				$args = array ();
				
				if ( $method !== $umethod ) return $this->Fault( "ambiguous method call ({$method} != {$umethod})" );
				
				foreach ( $xargs as $k => $xv ) {
					$v = ( string ) $xv;
					
					if ( is_numeric( $v ) && ($v{0} !== "0") ) {
						if ( ( int ) $v == ( float ) $v ) {
							$args[$k] = ( int ) $v;
						} else {
							$args[$k] = ( float ) $v;
						}
					} else {
						$args[$k] = $v;
					}
				}
				
				if ( $method ) {
					if ( $eargs = $this->exports[$method] ) {
						$apos = 0;
						$cargs = array ();
						
						foreach ( $eargs as $earg ) {
							foreach ( $args as $k => $v )
								if ( $earg["name"] === $k ) {
									$cargs[$apos] = $v;
									break;
								}
							++ $apos;
						}
						
						$this->method_name = $method;
						
						try {
							$ret = $this->on_Call( $method, $cargs );
						} catch ( Exception $e ) {
							return $this->Fault( $e->getMessage() );
						}
					} else {
						return $this->Fault( "undefined method" );
					}
				} else {
					return $this->Fault( "could not find request block" );
				}
				
				$this->Set_Content_Type( "text/xml" );
				return $this->SOAP_Add_Response_Envelope( "<{$method}_Response>" . $this->Variable_To_SOAP_String( $ret ) . "</{$method}_Response>" );
			} else if ( $purl[1] === "xsd" ) {
				$this->Set_Content_Type( "text/xml" );
				return $this->Get_XSD( $purl[0] );
			} else {
				return $this->Fault( "incorrect url" );
			}
		} else if ( ($purl[0] === "wsdl") || ($purl[0] === "") ) {
			$this->Set_Content_Type( "text/xml" );
			return $this->Get_WSDL();
		} else {
			$this->Set_Response_Status( 403 );
			return $this->Fault( "incorrect url" );
		}
	}
	
	/* The value returned by on_Call() will be sent back as the SOAP method call response. */
	abstract public function on_Call ( $method, $args );

}

?>