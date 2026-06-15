<?php

namespace App\Exceptions;

use Exception;

class NoBlockedDateFoundException extends Exception
{
    protected $code = 'no_blocked_date_found';
    protected $message = 'No blocked date found.';
    protected $error_description = 'No blocked date found.';
    protected $httpStatusCode = 404;
}
