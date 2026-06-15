<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class PasswordResetInvalidException extends HttpException
{
    public function __construct(
        string $message = 'Password request is invalid.',
        int $statusCode = 400,
    ) {
        parent::__construct($statusCode, $message);
    }
}
