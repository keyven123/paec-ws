<?php

namespace App\Exceptions;

use Exception;

class NoEventTicketFoundException extends Exception
{
    protected $code = 'no_event_ticket_found';
    protected $message = 'No event ticket found.';
    protected $error_description = 'No event ticket found.';
    protected $httpStatusCode = 404;
}
