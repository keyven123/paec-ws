<?php

namespace App\Exceptions;

use Exception;

class NoVenueFoundException extends Exception
{
    protected $code = 'no_venue_found';
    protected $message = 'No venue found.';
    protected $error_description = 'No venue found.';
    protected $httpStatusCode = 404;
}
