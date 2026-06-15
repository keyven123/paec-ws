<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountInactiveException extends HttpException
{
    public function __construct(
        string $message = 'Account is inactive',
        int $statusCode = 400,
    ) {
        parent::__construct($statusCode, $message);
    }
}
