<?php

namespace App\Helpers;

use Carbon\Carbon;
use App\Exceptions\InvalidTokenException;
use App\Exceptions\TokenExpiredException;
use App\Exceptions\UnauthorizedException;

class TokenParserHelper
{
    /**
     * @param string|null $bearerToken
     * @return mixed
     * @throws InvalidTokenException
     * @throws TokenExpiredException
     * @throws UnauthorizedException
     */
    public static function getClaims(?string $bearerToken): mixed
    {
        if (!$bearerToken) {
            throw new UnauthorizedException();
        }

        $tokenParts = explode(".", $bearerToken);
        $tokenPayload = base64_decode($tokenParts[1] ?? null);
        $decodedPayload = json_decode($tokenPayload);

        if (is_null($decodedPayload)) {
            throw new InvalidTokenException();
        }

        if ($decodedPayload->exp < Carbon::now()->timestamp) {
            throw new TokenExpiredException();
        }

        return $decodedPayload;
    }
}
