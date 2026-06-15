<?php

namespace App\Exceptions;

use Exception;

class NoOrganizationPlatformComFoundException extends Exception
{
    protected $code = 'no_organization_platform_com_found';

    protected $message = 'No organization platform commission log found.';

    protected $error_description = 'No organization platform commission log found.';

    protected $httpStatusCode = 404;
}
