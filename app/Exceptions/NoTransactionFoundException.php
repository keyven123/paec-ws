<?php

namespace App\Exceptions;

use Exception;

class NoTransactionFoundException extends Exception
{
    protected $code = 'no_transaction_found';
    protected $message = 'No transaction found.';
    protected $error_description = 'No transaction found.';
    protected $httpStatusCode = 404;
}
