<?php

namespace App\Http\Middleware;

use App\Constants\GeneralConstants;
use App\Helpers\GeneralHelper;
use App\Helpers\TokenParserHelper;
use App\Exceptions\UnauthorizedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAbility
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        try {
            $jwtPayload = TokenParserHelper::getClaims($request->bearerToken());

            // Superadmin: full access (also covers legacy DBs where role permissions were never synced
            // because SuperadminRolePermissionSeeder used the wrong Role::where() argument.)
            if (($jwtPayload->role ?? null) === GeneralConstants::ROLES['SUPER_ADMIN']['name']) {
                return $next($request);
            }

            $rawPermissions = $jwtPayload->permissions ?? [];
            $permissions = is_array($rawPermissions)
                ? $rawPermissions
                : array_values((array) $rawPermissions);

            $scopedPermissions = GeneralHelper::getScope($permissions);
            $hasAbility = false;
            foreach ($abilities as $ability) {
                if (in_array($ability, $scopedPermissions, true)) {
                    $hasAbility = true;
                    break;
                }
            }

            if ($permissions === [] || !$hasAbility) {
                // User is authenticated but doesn't have permission - return 403 Forbidden
                throw new UnauthorizedException('Permission denied', 403);
            }

            return $next($request);
        } catch (UnauthorizedException $e) {
            // Re-throw UnauthorizedException as-is (it may have custom status code)
            throw $e;
        } catch (\Exception $e) {
            // For other exceptions (like token parsing errors), return 401 Unauthorized
            throw new UnauthorizedException('Unauthenticated', 401);
        }
    }
}
