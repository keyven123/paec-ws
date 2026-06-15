<?php

namespace App\Exceptions;

use Exception;

class NoOrganizationFoundException extends Exception
{
    protected $code = 'no_organization_found';
    protected $message = 'No organization found.';
    protected $error_description = 'No organization found.';
    protected $httpStatusCode = 404;
}
