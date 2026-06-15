<?php

namespace App\Exceptions;

use Exception;

class NoScheduleTimeFoundException extends Exception
{
    protected $code = 'no_schedule_time_found';
    protected $message = 'No schedule time found.';
    protected $error_description = 'No schedule time found.';
    protected $httpStatusCode = 404;
}
