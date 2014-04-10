<?php

namespace Flake;

require_once "flake/flake.php";

abstract class Line_Input_Connection extends \Flake\Connection_Handler {
	
	const MAX_LENGTH = 16384;
	const EOL_SEPARATOR = "\n";
	private $line_buffer = "";

	final public function on_Read ( $data )
	{
		$this->line_buffer .= $data;
		
		while ( ($p = strrpos( $this->line_buffer, self::EOL_SEPARATOR )) !== false ) {
			$lines = explode( self::EOL_SEPARATOR, substr( $this->line_buffer, 0, $p ) );
			$this->line_buffer = substr( $this->line_buffer, $p + strlen( self::EOL_SEPARATOR ) );
			foreach ( $lines as $line )
				$this->on_Read_Line( rtrim( $line, "\r\n" ) . self::EOL_SEPARATOR );
		}
		
		if ( strlen( $this->line_buffer ) > self::MAX_LENGTH ) {
			$this->on_Read_Line( $this->line_buffer );
			$this->line_buffer = "";
		}
	}

	abstract public function on_Read_Line ( $data );

}

?>