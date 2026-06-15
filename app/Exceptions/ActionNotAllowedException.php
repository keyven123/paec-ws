<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ActionNotAllowedException extends HttpException
{
    public function __construct(
        string $message = 'Action not allowed',
        int $statusCode = 403,
    ) {
        parent::__construct($statusCode, $message);
    }
}
