<?php

namespace App\Exceptions;

use Exception;

class NoTicketSeatFoundException extends Exception
{
    protected $code = 'no_ticket_seat_found';
    protected $message = 'No ticket seat found.';
    protected $error_description = 'No ticket seat found.';
    protected $httpStatusCode = 404;
}
