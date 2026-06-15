<?php

namespace App\Exceptions;

use Exception;

class NoEventFoundException extends Exception
{
    protected $code = 'no_event_found';
    protected $message = 'No event found.';
    protected $error_description = 'No event found.';
    protected $httpStatusCode = 404;
}
