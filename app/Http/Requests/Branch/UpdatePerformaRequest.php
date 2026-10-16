<?php

namespace App\Http\Requests\Branch;

use App\Models\Performa;

/**
 * UpdatePerformaRequest
 *
 * The same payload as issuing a performa, sent against an open one. The lines
 * are the ones that may still change; paid or returned lines are not sent.
 */
class UpdatePerformaRequest extends StorePerformaRequest
{
    /**
     * Edit this performa.
     */
    public function authorize(): bool
    {
        $performa = $this->route('performa');

        return $performa instanceof Performa
            && ($this->user()?->can('update', $performa) ?? false);
    }

    /**
     * A performa whose other lines are locked may be saved with none new.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['lines'] = ['present', 'array'];

        return $rules;
    }
}
