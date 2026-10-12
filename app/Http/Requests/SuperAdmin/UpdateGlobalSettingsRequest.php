<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\SettingGroup;
use App\Enums\TaxType;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Support\Settings\SettingCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * UpdateGlobalSettingsRequest
 *
 * Validates one SuperAdmin settings group. Rules come from SettingCatalog
 * so a new key cannot slip in through the form. Boolean fields must be
 * real booleans — the string "yes" is refused.
 */
class UpdateGlobalSettingsRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * SuperAdmin with settings.global.manage.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageGlobalSettings') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $group = $this->group();
        $rules = [];

        foreach (SettingCatalog::forGroup($group) as $definition) {
            $key = $definition['key'];

            if ($definition['widget'] === 'file') {
                $rules[$key] = ['nullable', 'file', 'max:512'];

                continue;
            }

            if ($definition['widget'] === 'toggle') {
                $rules[$key] = ['required', 'boolean'];

                continue;
            }

            $rules[$key] = $definition['rules'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.required' => 'This setting is required.',
            '*.boolean' => 'That must be yes or no.',

            'max_discount_percentage.max' => 'The discount ceiling cannot exceed 100%.',
            'numbering_jewellery_prefix.regex' => 'Use uppercase letters, numbers and an optional trailing dash. No spaces.',
            'numbering_stone_prefix.regex' => 'Use uppercase letters, numbers and an optional trailing dash. No spaces.',
            'numbering_customer_prefix.regex' => 'Use uppercase letters, numbers and an optional trailing dash. No spaces.',
            'numbering_quotation_prefix.regex' => 'Use uppercase letters, numbers and an optional trailing dash. No spaces.',
            'numbering_invoice_prefix.regex' => 'Use uppercase letters, numbers and an optional trailing dash. No spaces.',
            'numbering_intake_prefix.regex' => 'Use uppercase letters, numbers and an optional trailing dash. No spaces.',
            'timezone.timezone' => 'Choose a valid timezone identifier.',
        ];
    }

    /**
     * Cross-field checks the catalog cannot express alone.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {

            $allowed = array_column(SettingCatalog::forGroup($this->group()), 'key');
            $ignored = ['_token', '_method'];
            $unknown = array_values(array_diff(array_keys($this->all()), $allowed, $ignored));

            if ($unknown !== []) {
                $validator->errors()->add(
                    'settings',
                    'Unknown setting: '.implode(', ', $unknown).'. Keys cannot be created from a form.'
                );
            }
        });
    }

    /**
     * Catalog values ready for SettingService::setGroup, files excluded.
     *
     * @return array<string, mixed>
     */
    public function settingValues(): array
    {
        $values = [];

        foreach (SettingCatalog::forGroup($this->group()) as $definition) {
            $key = $definition['key'];

            if ($definition['widget'] === 'file') {
                continue;
            }

            if ($definition['widget'] === 'toggle') {
                $values[$key] = $this->boolean($key);

                continue;
            }

            if ($this->exists($key)) {
                $values[$key] = $this->input($key);
            }
        }

        return $values;
    }

    /**
     * The group being saved, from the route.
     */
    public function group(): SettingGroup
    {
        $group = SettingGroup::tryFrom((string) $this->route('group'));

        if ($group === null || ! in_array($group, SettingGroup::globalGroups(), true)) {
            abort(404);
        }

        return $group;
    }
}
