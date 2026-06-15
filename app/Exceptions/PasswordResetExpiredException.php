<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class PasswordResetExpiredException extends HttpException
{
    public function __construct(
        string $message = 'Your password reset request has timed out.',
        int $statusCode = 400,
    ) {
        parent::__construct($statusCode, $message);
    }
}
