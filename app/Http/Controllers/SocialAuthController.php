<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Str;
use App\Constants\GeneralConstants;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function googleRedirect()
    {
        $returnUrl = request()->query('return_url');
        $driver = Socialite::driver('google')->stateless();
        if ($returnUrl && $this->isSafeRedirectPath($returnUrl)) {
            $driver->with(['state' => $returnUrl]);
        }
        return $driver->redirect();
    }

    public function googleCallback()
    {
        $socialUser = Socialite::driver('google')
            ->stateless()
            ->user();

        return $this->issueJwt($socialUser, 'google', request()->query('state'));
    }

    public function facebookRedirect()
    {
        $returnUrl = request()->query('return_url');
        $driver = Socialite::driver('facebook')->stateless();
        if ($returnUrl && $this->isSafeRedirectPath($returnUrl)) {
            $driver->with(['state' => $returnUrl]);
        }
        return $driver->redirect();
    }

    public function facebookCallback()
    {
        $socialUser = Socialite::driver('facebook')
            ->stateless()
            ->user();

        return $this->issueJwt($socialUser, 'facebook', request()->query('state'));
    }

    /**
     * Allow only relative paths (e.g. /event/slug) to avoid open redirect.
     */
    private function isSafeRedirectPath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }
        $path = trim($path);
        return $path[0] === '/' && !preg_match('#^//#', $path);
    }

    private function issueJwt($socialUser, string $provider, ?string $returnUrl = null)
    {
        $email = $socialUser->getEmail();

        if (!$email) {
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            return redirect("{$frontendUrl}/auth/callback?error=" . urlencode('No email returned by provider. Please ensure email permission is enabled.'));
        }

        $role = Role::whereCode(GeneralConstants::ROLES['CUSTOMER']['name'])->first();

        if (!$role) {
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            return redirect("{$frontendUrl}/auth/callback?error=" . urlencode('Customer role not found. Please contact support.'));
        }

        $fullName = $socialUser->getName() ?: $socialUser->getNickname() ?: 'User Account';
        $nameParts = explode(' ', trim($fullName), 2);
        $firstName = $nameParts[0] ?? 'User';
        $lastName = $nameParts[1] ?? '';

        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = new User();
            $user->email = $email;
            $user->role_uuid = $role->uuid;
            $user->password = bcrypt(Str::random(32));
        }

        $user->first_name = ucwords($firstName);
        $user->last_name = ucwords($lastName);
        $user->email_verified_at = $user->email_verified_at ?: now();
        $user->provider = $provider;
        $user->provider_id = $socialUser->getId();
        $user->avatar = $socialUser->getAvatar();
        $user->save();

        $user->load(['role']);

        $token = auth('api')->login($user);

        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        $userData = [
            'uuid' => $user->uuid,
            'first_name' => ucwords($user->first_name),
            'last_name' => ucwords($user->last_name),
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
        ];

        $queryParams = [
            'access_token' => $token,
            'user' => base64_encode(json_encode($userData)),
            'role' => $user->role->code,
        ];
        if ($returnUrl && $this->isSafeRedirectPath($returnUrl)) {
            $queryParams['redirect'] = $returnUrl;
        }

        return redirect("{$frontendUrl}/auth/callback?" . http_build_query($queryParams));
    }
}
