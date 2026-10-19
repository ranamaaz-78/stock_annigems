<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\SettingGroup;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SettingsHistoryRequest
 *
 * Filters the SuperAdmin change-history table by group and date range.
 */
class SettingsHistoryRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * SuperAdmin with settings.history.view.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewSettingsHistory') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'group' => ['nullable', Rule::enum(SettingGroup::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => __('The end date must be on or after the start date.'),
        ];
    }
}
