<?php

namespace App\Exceptions;

use Exception;

class NoCategoryFoundException extends Exception
{
    protected $code = 'no_category_found';
    protected $message = 'No category found.';
    protected $error_description = 'No category found.';
    protected $httpStatusCode = 404;
}
