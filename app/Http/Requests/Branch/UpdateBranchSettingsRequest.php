<?php

namespace App\Http\Requests\Branch;

use App\Enums\SettingGroup;
use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Support\Settings\BranchSettingCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * UpdateBranchSettingsRequest
 *
 * Validates one Admin-owned branch settings group (print or footers).
 * Contact details use UpdateBranchContactRequest and edit the Branch row.
 */
class UpdateBranchSettingsRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Admin of this branch with settings.branch.manage.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageBranchSettings') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (BranchSettingCatalog::forGroup($this->group()) as $definition) {
            $rules[$definition['key']] = $definition['widget'] === 'toggle'
                ? ['required', 'boolean']
                : $definition['rules'];
        }

        return $rules;
    }

    /**
     * Refuse keys the branch catalog does not own.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = array_column(BranchSettingCatalog::forGroup($this->group()), 'key');
            $unknown = array_values(array_diff(array_keys($this->except(['_token', '_method'])), $allowed));

            if ($unknown !== []) {
                $validator->errors()->add(
                    'settings',
                    __('Unknown setting: :unknown. Keys cannot be created from a form.', ['unknown' => implode(', ', $unknown)])
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.required' => __('This setting is required.'),
            '*.boolean' => __('That must be yes or no.'),
            'invoice_footer_note.max' => __('The invoice footer cannot exceed 500 characters.'),
            'quotation_footer_note.max' => __('The quotation footer cannot exceed 500 characters.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingValues(): array
    {
        $values = [];

        foreach (BranchSettingCatalog::forGroup($this->group()) as $definition) {
            $key = $definition['key'];

            $values[$key] = $definition['widget'] === 'toggle'
                ? $this->boolean($key)
                : $this->input($key);
        }

        return $values;
    }

    /**
     * Print or invoice group from the route.
     */
    public function group(): SettingGroup
    {
        $group = SettingGroup::tryFrom((string) $this->route('group'));

        if (! in_array($group, [SettingGroup::PRINT, SettingGroup::INVOICE], true)) {
            abort(404);
        }

        return $group;
    }
}
