<?php

namespace App\Exceptions;

use Exception;

class NoPasswordSetupFoundException extends Exception
{
    protected $code = 'no_password_setup_found';
    protected $message = 'No password setup found.';
    protected $error_description = 'No password setup found.';
    protected $httpStatusCode = 404;
}


