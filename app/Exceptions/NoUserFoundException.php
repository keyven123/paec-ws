<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class NoUserFoundException extends HttpException
{
    public function __construct(
        string $message = 'No user found',
        int $statusCode = 404,
    ) {
        parent::__construct($statusCode, $message);
    }
}
