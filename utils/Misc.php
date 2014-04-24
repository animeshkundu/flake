<?php

/* Functions that do not fit elsewhere. */
require_once 'Logger.php';

function Info ( $message, $severityType = 'INFO', $summary = 'MISC', $verbose = 0 )
{
	Logger::Log( $message, $severityType, $summary, $verbose );
}

function Error ( $message, $severityType = 'ERROR', $summary = 'MISC', $verbose = 0 )
{
	Logger::Log( $message, $severityType, $summary, $verbose );
}