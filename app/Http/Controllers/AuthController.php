<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use App\Models\ConfirmationToken;
use App\Constants\GeneralConstants;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\UserRegistrationRequest;
use App\Http\Repositories\PasswordResetRepository;
use App\Notifications\EmailVerificationNotification;

class AuthController extends Controller
{
    protected $passwordReset;

    public function __construct(PasswordResetRepository $passwordReset)
    {
        $this->passwordReset = $passwordReset;
    }

    public function register(UserRegistrationRequest $request)
    {
        $data = $request->validated();

        $role = Role::whereCode(GeneralConstants::ROLES['CUSTOMER']['name'])->first();
        $user = User::create([
            'role_uuid' => $role->uuid,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'address_line_1' => $data['address'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'],
            'password' => $data['password'],
            'email_verified_at' => now(),
            'terms_accepted_at' => now(),
            'marketing_consent' => false,
            'is_first_time_login' => false,
        ]);

        $token = auth('api')->login($user);

        return response()->json(array_merge([
            'success' => true,
            'message' => __('auth.registration_success'),
        ], json_decode($this->respondWithToken($token, $user)->getContent(), true)), 201);
    }

    public function login(LoginRequest $request)
    {
        $payload = $request->validated();

        $user = User::where('email', $payload['email'])->first();

        if (!$user) {
            return response()->json(['message' => 'invalid credentials'], 401);
        }

        if ($user && (bool) $user->is_migrated) {
            $this->passwordReset->initiate($payload);

            return response()->json([
                'data' => [
                    'message' => "For your account's security, we've recently updated our authentication system. Please check your email for a link to reset your password before you can log in again."
                ]
            ], 201);
        }

        if (is_null($user->email_verified_at)) {
            return response()->json(['message' => 'Email not verified'], 401);
        }

        if (!$token = auth('api')->attempt($payload)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        return $this->respondWithToken($token, $user);
    }

    public function me()
    {
        $user = auth('api')->user()->load(['role', 'profileImage']);

        return (new UserResource($user))->response();
    }

    public function refresh()
    {
        $newToken = auth('api')->refresh();

        return $this->respondWithToken($newToken, auth('api')->user());
    }

    public function logout()
    {
        auth('api')->logout();
        return response()->json(['message' => 'Logged out']);
    }

    public function verifyEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('auth.user_not_found')
            ], 404);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => __('auth.email_already_verified')
            ], 400);
        }

        $confirmationToken = ConfirmationToken::where('user_uuid', $user->uuid)
            ->where('token', $data['token'])
            ->first();

        if (!$confirmationToken || !$confirmationToken->isValid()) {
            return response()->json([
                'success' => false,
                'message' => __('auth.invalid_or_expired_token')
            ], 400);
        }

        // Verify the user's email
        $user->update([
            'email_verified_at' => now(),
            'is_first_time_login' => false,
        ]);

        // Delete the used token
        $confirmationToken->delete();

        $user->refresh();
        $token = auth('api')->login($user);
        $tokenPayload = json_decode($this->respondWithToken($token, $user)->getContent(), true);

        return response()->json(array_merge([
            'success' => true,
            'message' => __('auth.email_verified_successfully'),
        ], $tokenPayload));
    }

    public function resendVerification(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('auth.user_not_found')
            ], 404);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => __('auth.email_already_verified')
            ], 400);
        }

        // Create new confirmation token
        $confirmationToken = ConfirmationToken::createForUser($user->uuid, config('auth.confirmation_token_ttl', 60));

        // Send email verification notification
        $user->notify(new EmailVerificationNotification($confirmationToken));

        return response()->json([
            'success' => true,
            'message' => __('auth.verification_email_sent'),
        ]);
    }

    protected function respondWithToken(string $token, $user = null)
    {
        if ($user) {
            $user->load(['role']);
        }

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user' => [
                'uuid' => $user->uuid,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
            ],
            'role' => $user->role->code,
            'expires_in'   => Carbon::now()->addMinutes(auth('api')->factory()->getTTL())->toDateTimeString(),
        ]);
    }
}
