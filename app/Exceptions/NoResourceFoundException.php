<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class NoResourceFoundException extends HttpException
{
    public function __construct(
        string $message = 'No resource found',
        int $statusCode = 404,
    ) {
        parent::__construct($statusCode, $message);
    }
}
