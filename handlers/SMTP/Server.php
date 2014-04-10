<?php

namespace Flake\SMTP;

require_once "flake/handlers/Line_Input_Connection.php";

/* SMTP Service handler class. */
abstract class Server extends \Flake\Line_Input_Connection {
	
	const SERVER_STRING = "";
	
	public $hostname;
	protected $helo_message = "";
	protected $env_from = "";
	protected $env_rcpt = array ();
	protected $data_buffer = "";
	private $indata = false;

	public function __construct ()
	{
		$this->hostname = php_uname( "n" );
	}

	public function on_Accept ()
	{
		$this->Write( "200 " . $this->hostname . " SMTP " . (static::SERVER_STRING ? static::SERVER_STRING : "flake/0.01") . "\n" );
	}

	final public function on_Read_Line ( $data )
	{
		if ( ! $this->indata ) {
			$updata = strtoupper( $data );
			
			if ( strpos( $updata, "HELO" ) === 0 ) {
				strtok( $data, " " );
				$this->helo_message = trim( strtok( "" ) );
				
				if ( ! $this->on_SMTP_HELO( $this->helo_message ) ) {
					$this->Disconnect();
					break;
				}
				
				$this->Write( "250 " . $this->hostname . " Hello\n" );
			} else if ( strpos( $updata, "MAIL FROM" ) === 0 ) {
				strtok( $data, ":" );
				$this->env_from = trim( strtok( "" ) );
				
				if ( ! $this->on_SMTP_MAIL_FROM( $this->env_from ) ) break;
				$this->Write( "250 " . $this->env_from . "... Sender ok\n" );
			} else if ( strpos( $updata, "RCPT TO" ) === 0 ) {
				strtok( $data, ":" );
				$this->env_rcpt[] = $rcpt = trim( strtok( "" ) );
				
				if ( ! $this->on_SMTP_RCPT_TO( $rcpt ) ) break;
				$this->Write( "250 " . $rcpt . "... Recipient ok\n" );
			} else if ( strpos( $updata, "DATA" ) === 0 ) {
				$this->Write( "354 Enter mail, end with '.' on a line by itself\n" );
				$this->indata = true;
			} else if ( strpos( $updata, "QUIT" ) === 0 ) {
				$this->Write( "251 " . $this->hostname . " closing connection\n", array ( 
					$this, 
					"Disconnect" ) );
			} else {
				if ( ! $this->on_SMTP_Unhandled( trim( $data ) ) ) break;
			}
		} else {
			if ( rtrim( $data ) !== "." ) {
				$this->data_buffer .= $data;
			} else {
				if ( $this->on_Mail( $this->env_from, $this->env_rcpt, $this->data_buffer ) ) {
					$this->Write( "250 Message accepted\n" );
				} else {
					$this->Write( "554 Message rejected\n" );
				}
				
				$this->indata = false;
				$this->env_from = "";
				$this->env_rcpt = array ();
			}
		}
	}
	
	/* Event called on SMTP HELO reception. Extend this method to return the 
	   boolean status of the session (false = disconnect client) */
	public function on_SMTP_HELO ( $data )
	{
		return true;
	}

	public function on_SMTP_MAIL_FROM ( $data )
	{
		return true;
	}

	public function on_SMTP_RCPT_TO ( $data )
	{
		return true;
	}
	
	/* Event called on unknown SMTP command reception. */
	public function on_SMTP_Unhandled ( $data )
	{
		$this->Write( "500 Unknown command : '$data'\n" );
		return true;
	}

	public function on_Mail ( $env_from, $env_to, $data )
	{}

}

?>