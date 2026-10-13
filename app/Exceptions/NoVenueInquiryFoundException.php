<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class NoVenueInquiryFoundException extends HttpException
{
    public function __construct(
        string $message = 'Venue inquiry not found',
        int $statusCode = 404,
    ) {
        parent::__construct($statusCode, $message);
    }
}
