<?php

namespace App\Exceptions;

use Exception;

class TokenExpiredException extends Exception
{
    protected $code = 'token_expired';
    protected $message = 'Token expired';
    protected $error_description = 'Token expired';
    protected $httpStatusCode = 401;
}
