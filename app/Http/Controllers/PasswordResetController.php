<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Models\Otp;
use App\Http\Repositories\OtpRepository;
use Illuminate\Http\Request;
use App\Models\PasswordReset;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Repositories\PasswordResetRepository;
use App\Http\Repositories\PasswordSetupRepository;
use App\Exceptions\PasswordResetExpiredException;
use App\Exceptions\PasswordResetInvalidException;
use App\Exceptions\NoPasswordSetupFoundException;
use App\Http\Requests\PasswordReset\PasswordResetInitiateRequest;
use App\Http\Requests\PasswordReset\PasswordResetUpdatePasswordRequest;
use App\Http\Requests\PasswordReset\AdminPasswordResetInitiateRequest;
use App\Http\Requests\PasswordReset\ExpiredPasswordResetUpdatePasswordRequest;

class PasswordResetController extends Controller
{
    protected $passwordReset;
    protected $passwordSetup;
    protected $otp;

    /**
     * @param PasswordResetRepository $passwordReset
     * @param PasswordSetupRepository $passwordSetup
     * @param OtpRepository $otp
     */
    public function __construct(
        PasswordResetRepository $passwordReset,
        PasswordSetupRepository $passwordSetup,
        OtpRepository $otp
    ) {
        $this->passwordReset = $passwordReset;
        $this->passwordSetup = $passwordSetup;
        $this->otp = $otp;
    }

    /**
     * @param PasswordResetInitiateRequest $request
     * @return JsonResponse
     */
    public function initiate(PasswordResetInitiateRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $this->passwordReset->initiate($payload);

        return response()->json([
            'data' => [
                'message' => 'If an account is registered with this email, you will receive a password reset link shortly.',
            ],
        ], 201);
    }

    /**
     * @param AdminPasswordResetInitiateRequest $request
     * @return JsonResponse
     */
    public function adminInitiate(AdminPasswordResetInitiateRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $this->passwordReset->initiateAdmin($payload);

        return response()->json([
            'data' => [
                'message' => 'If an account is registered with this email, you will receive a password reset link shortly.',
            ],
        ], 201);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws PasswordResetInvalidException
     * @throws PasswordResetExpiredException
     */
    public function confirmPasswordReset(Request $request): JsonResponse
    {
        $otp = Otp::whereUuid($request->get('uuid'))->first();

        $type = $request->get('type');
        if (is_null($otp)) {
            throw new PasswordResetInvalidException();
        }

        if ($otp->isExpired()) {
            throw new PasswordResetExpiredException();
        }

        $passwordReset = $this->otp->confirm($otp);

        if ($type && $type === 'email_verification') {
            $this->passwordSetup->emailVerified($otp);
        }

        return response()->json([
            'data' => [
                'user_type' => $otp->otpable->resettable_type == 'App\Models\AdminUser' ? 'admin' : 'user',
                'password_reset_uuid' => $passwordReset->uuid
            ]
        ]);
    }

    /**
     * @param PasswordResetUpdatePasswordRequest $request
     * @return JsonResponse
     */
    public function updatePassword(PasswordResetUpdatePasswordRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $passwordReset = PasswordReset::whereUuid($payload['password_reset_uuid'])->first();
        $role = $passwordReset->resettable->role;
        $this->passwordReset->changePassword($passwordReset, $payload);

        return response()->json([
            'data' => [
                'user_type' => $role->code == GeneralConstants::ROLES['CUSTOMER']['name'] ? 'user' : 'admin',
                'is_admin' => $role->is_admin,
                'message' => 'You have successfully changed your password.'
            ]
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws PasswordResetInvalidException
     * @throws PasswordResetExpiredException
     */
    public function confirmExpiredPasswordReset(Request $request): JsonResponse
    {
        $otp = Otp::whereUuid($request->get('uuid'))->first();

        if (is_null($otp)) {
            throw new PasswordResetInvalidException();
        }

        if ($otp->isExpired()) {
            throw new PasswordResetExpiredException();
        }

        $passwordSetup = $this->otp->confirm($otp);

        // Try to determine user type from email
        $userType = 'admin';
        $isAdmin = false;

        $adminUser = \App\Models\AdminUser::where('email', $passwordSetup->email)->first();
        if ($adminUser && $adminUser->role) {
            $userType = 'admin';
            $isAdmin = $adminUser->role->is_admin ?? false;
        } else {
            $user = \App\Models\User::where('email', $passwordSetup->email)->first();
            if ($user) {
                $userType = 'user';
            }
        }

        return response()->json([
            'data' => [
                'password_set_uuid' => $passwordSetup->uuid,
                'user_type' => $userType,
                'is_admin' => $isAdmin
            ]
        ]);
    }

    /**
     * @param ExpiredPasswordResetUpdatePasswordRequest $request
     * @return JsonResponse
     * @throws NoPasswordSetupFoundException
     */
    public function updateExpiredPassword(ExpiredPasswordResetUpdatePasswordRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $passwordSetup = \App\Models\PasswordSetup::whereUuid($payload['password_set_uuid'])->first();

        if (is_null($passwordSetup)) {
            throw new NoPasswordSetupFoundException();
        }

        $result = $this->passwordSetup->processPasswordSetup($passwordSetup, $payload['password']);

        if (!$result['success']) {
            return response()->json([
                'data' => [
                    'message' => 'Failed to set password. Please contact support.',
                    'user_type' => 'user',
                    'is_admin' => false
                ]
            ], 400);
        }

        return response()->json([
            'data' => [
                'message' => 'You have successfully set your password.',
                'user_type' => $result['user_type'],
                'is_admin' => $result['is_admin']
            ]
        ]);
    }
}
