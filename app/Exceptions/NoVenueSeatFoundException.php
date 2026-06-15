<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class NoVenueSeatFoundException extends HttpException
{
    public function __construct(
        string $message = 'No venue seat found',
        int $statusCode = 404,
    ) {
        parent::__construct($statusCode, $message);
    }
}
