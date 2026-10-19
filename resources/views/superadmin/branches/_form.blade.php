{{-- Shared branch form fields for create/edit. --}}
@php
    /** @var \App\Models\Branch|null $branch */
    $branch = $branch ?? null;
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <div class="sm:col-span-2">
        <p class="font-label-caps text-on-surface-variant mb-2">{{ __('Basic information') }}</p>
    </div>

    <x-form.input name="name" :label="__('Branch Name')" :value="$branch?->name" required maxlength="150" />
    <x-form.input name="code" :label="__('Branch Code')" :value="$branch?->code" required maxlength="20" placeholder="GRW-01" data-pattern="^[A-Za-z0-9\-]+$" data-error-pattern="{{ __('Letters, numbers, and dashes only.') }}" />

    <div class="sm:col-span-2">
        <x-form.textarea name="address" :label="__('Address')" :value="$branch?->address" required maxlength="500" />
    </div>

    <x-form.input name="city" :label="__('City')" :value="$branch?->city" required maxlength="100" />
    <x-form.input name="contact_number" :label="__('Contact Number')" :value="$branch?->contact_number" required maxlength="30" />
    <x-form.input name="email" :label="__('Branch Email')" type="email" :value="$branch?->email" maxlength="255" />

    <x-form.select
        name="status"
        :label="__('Status')"
        :options="['active' => __('Active'), 'inactive' => __('Inactive')]"
        :value="old('status', $branch?->status?->value ?? 'active')"
        required
    />
</div>
