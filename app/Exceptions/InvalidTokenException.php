<?php

namespace App\Exceptions;

use Exception;

class InvalidTokenException extends Exception
{
    protected $message = 'Invalid token';
    protected $code = 'invalid_token';
    protected $error_description = 'Invalid token';
    protected $httpStatusCode = 401;
}
