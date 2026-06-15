<?php

namespace App\Exceptions;

use Exception;

class NoPromoCodeFoundException extends Exception
{
    protected $code = 'no_promo_code_found';
    protected $message = 'No promo code found.';
    protected $error_description = 'No promo code found.';
    protected $httpStatusCode = 404;
}

