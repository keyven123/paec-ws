<?php

namespace App\Exceptions;

use Exception;

class NoEventSectionFoundException extends Exception
{
    protected $code = 'no_event_section_found';
    protected $message = 'No event section found.';
    protected $error_description = 'No event section found.';
    protected $httpStatusCode = 404;
}
