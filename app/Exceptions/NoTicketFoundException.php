<?php

namespace App\Exceptions;

use Exception;

class NoTicketFoundException extends Exception
{
    protected $code = 'no_ticket_found';
    protected $message = 'No ticket found.';
    protected $error_description = 'No ticket found.';
    protected $httpStatusCode = 404;
}
