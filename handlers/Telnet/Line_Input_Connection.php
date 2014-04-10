<?php

namespace Flake\Telnet;

require_once "flake/handlers/Telnet/Connection.php";

/* Telnet with line input protocol handler handler class. */
abstract class Line_Input_Connection extends \Flake\Telnet\Connection {
	
	const MAX_LENGTH = 16384;
	const EOL_SEPARATOR = "\n";
	private $line_buffer = "";
	
	/* This event is called every time there is data received. The behavior of on_Telnet_Read()
	 * is to buffer data until a complete line in available and then pass it to on_Telnet_Read_Line(). */
	public function on_Telnet_Read ( $data )
	{
		$this->line_buffer .= $data;
		
		while ( ($p = strrpos( $this->line_buffer, self::EOL_SEPARATOR )) !== false ) {
			$lines = explode( self::EOL_SEPARATOR, substr( $this->line_buffer, 0, $p ) );
			$this->line_buffer = substr( $this->line_buffer, $p + strlen( self::EOL_SEPARATOR ) );
			foreach ( $lines as $line )
				$this->on_Telnet_Read_Line( rtrim( $line, "\r\n" ) . self::EOL_SEPARATOR );
		}
		
		if ( strlen( $this->line_buffer ) > self::MAX_LENGTH ) {
			$this->on_Telnet_Read_Line( $this->line_buffer );
			$this->line_buffer = "";
		}
	}
	
	/* This event is called every time there is a line of data received. */
	abstract public function on_Telnet_Read_Line ( $s );

}

?>