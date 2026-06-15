<?php

namespace App\Exceptions;

use Exception;

class NoScheduleFoundException extends Exception
{
    protected $code = 'no_schedule_found';
    protected $message = 'No schedule found.';
    protected $error_description = 'No schedule found.';
    protected $httpStatusCode = 404;
}
