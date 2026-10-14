<?php

namespace App\Http\Requests\Dashboard;

use App\Services\AdminDashboardOverviewService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardOverviewRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', Rule::in(AdminDashboardOverviewService::PERIODS)],
        ];
    }
}
