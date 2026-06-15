<?php

namespace App\Exceptions;

use Exception;

class NoAdminUserFoundException extends Exception
{
    protected $code = 'no_admin_user_found';
    protected $message = 'No admin user found.';
    protected $error_description = 'No admin user found.';
    protected $httpStatusCode = 404;
}
