<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Exceptions\NoResourceFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\Dataset;
use App\Models\Organization;
use App\Helpers\GeneralHelper;
use App\Models\AdminUser;
use App\Models\Role;
use App\Notifications\SendInvitesToOrganizer;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class OrganizationRepository
{
    /**
     * @param Organization $organization
     */
    public function __construct(
        protected Organization $organization,
        protected AdminUser $adminUser,
        protected Role $role
    ) {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->organization
            ->with(['image', 'banks'])
            ->filters($filters)
            ->orderBy('created_at', $filters['sort_by'] ?? 'desc')
            ->orderBy('name', 'asc');
    }

    /**
     * Fetch organization or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return Organization
     * @throws NoResourceFoundException
     */
    public function fetchOrThrow(string $key, string $value): Organization
    {
        $organization = $this->organization->with(['image', 'banks'])->where($key, $value)->first();

        if (is_null($organization)) {
            throw new NoResourceFoundException('Organization not found');
        }

        return $organization;
    }

    /**
     * @param array $payload
     * @return Organization
     */
    public function create(array $payload): Organization
    {
        $payload['contact_number'] = GeneralHelper::formatContactNumberToPh($payload['contact_number']);
        [$organizationPayload, $bankPayload] = OrganizationBankRepository::extractBankPayload($payload);
        $organizationPayload = GeneralHelper::unsetUnknownAndNullFields($organizationPayload, Organization::DATA);
        if (! array_key_exists('commission_percentage', $organizationPayload)) {
            $organizationPayload['commission_percentage'] = Dataset::merchantCommissionPercent();
        }
        if (! array_key_exists('payment_methods', $organizationPayload)) {
            $organizationPayload['payment_methods'] = Dataset::defaultPaymentMethods();
        }

        $organization = $this->organization->create($organizationPayload);

        if ($bankPayload !== []) {
            app(OrganizationBankRepository::class)->upsertDefaultBank($organization, $bankPayload);
        }

        return $organization->load('banks');
    }

    /**
     * @param array $payload
     * @return Organization
     */
    public function oldCreate(array $payload): Organization
    {
        $payload['contact_number'] = GeneralHelper::formatContactNumberToPh($payload['contact_number']);
        $payload = GeneralHelper::unsetUnknownAndNullFields($payload, Organization::DATA);
        if (! array_key_exists('commission_percentage', $payload)) {
            $payload['commission_percentage'] = Dataset::merchantCommissionPercent();
        }
        if (! array_key_exists('payment_methods', $payload)) {
            $payload['payment_methods'] = Dataset::defaultPaymentMethods();
        }

        return $this->organization->create($payload);
    }

    /**
     * @param Organization $organization
     * @param array $payload
     * @return bool|Organization
     */
    public function update(Organization $organization, array $payload): bool|Organization
    {
        [$organizationPayload, $bankPayload] = OrganizationBankRepository::extractBankPayload($payload);
        $organizationPayload = GeneralHelper::unsetUnknownAndNullFields($organizationPayload, Organization::DATA);

        // Validate bundle_tickets to ensure it doesn't include itself
        if (isset($organizationPayload['bundle_tickets']) && is_array($organizationPayload['bundle_tickets'])) {
            // Remove self from bundle_tickets if present
            $organizationPayload['bundle_tickets'] = array_filter(
                $organizationPayload['bundle_tickets'],
                fn($ticketUuid) => $ticketUuid !== $organization->uuid
            );
        }

        $organization->update($organizationPayload);

        if ($bankPayload !== []) {
            app(OrganizationBankRepository::class)->upsertDefaultBank($organization, $bankPayload);
        }

        return $organization;
    }

    /**
     * @param Organization $organization
     * @param array<int, array{name: string, value: bool, provider?: string}> $paymentMethods
     * @return Organization
     */
    public function updatePaymentMethods(Organization $organization, array $paymentMethods): Organization
    {
        $organization->update([
            'payment_methods' => \App\Support\OrganizationPaymentMethods::normalize($paymentMethods),
        ]);

        return $organization->fresh();
    }

    public function updateCommissionPercentage(Organization $organization, float $commissionPercentage): Organization
    {
        $organization->update([
            'commission_percentage' => round($commissionPercentage, 2),
        ]);

        return $organization->fresh();
    }

    /**
     * @param Organization $organization
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(Organization $organization): void
    {
        // Add any business logic for deletion here
        // For example, prevent deletion if ticket has been sold

        $organization->delete();
    }

    public function approve(Organization $organization): bool|Organization
    {
        return $organization->update([
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'approved_by' => auth('admin')->user()->uuid,
            'approved_at' => now(),
        ]);
    }

    public function reject(Organization $organization): bool|Organization
    {
        return $organization->update(['status' => GeneralConstants::ORGANIZER_STATUSES['REJECTED']]);
    }
    public function onboard(Organization $organization): bool|Organization
    {
        return $organization->update([
            'status' => GeneralConstants::ORGANIZER_STATUSES['ONBOARDED'],
        ]);
    }

    public function sendInvitation(Organization $organization): void
    {
        $secret = sprintf("%06d", mt_rand(1, 999999));
        $organization->update([
            'secret' => Hash::make($secret),
            'secret_expired_at' => Carbon::now()->addDay(),
            'send_invite_count' => (int)$organization->send_invite_count + 1
        ]);

        $organization->notify(new SendInvitesToOrganizer($secret));
    }

    public function onboardingRegister(array $payload, string $organizationUuid): AdminUser
    {
        $payload['accepted_terms'] = true;
        $payload['accepted_terms_at'] = now();
        $payload['email_verified_at'] = now();
        $payload['phone_number'] = GeneralHelper::formatContactNumberToPh($payload['phone_number']);
        $payload['organization_uuid'] = $organizationUuid;
        $role = $this->role->whereCode(GeneralConstants::ROLES['ORGANIZER']['name'])->first();
        $payload['role_uuid'] = $role->uuid;
        $payload = GeneralHelper::unsetUnknownAndNullFields($payload, AdminUser::DATA);
        // $adminUser->notify(new WelcomeOrganizerEmailNotification($adminUser));
        return $this->adminUser->create($payload);
    }

    /**
     * Get organization statistics by status
     * @return array
     */
    public function getStats(): array
    {
        $pending = $this->organization->where('status', GeneralConstants::ORGANIZER_STATUSES['PENDING'])->count();
        $onboarded = $this->organization->where('status', GeneralConstants::ORGANIZER_STATUSES['ONBOARDED'])->count();
        $approved = $this->organization->where('status', GeneralConstants::ORGANIZER_STATUSES['APPROVED'])->count();
        $total = $this->organization->count();

        return [
            'pending' => $pending,
            'onboarded' => $onboarded,
            'approved' => $approved,
            'total' => $total,
        ];
    }
}
