{{-- Shared fields for branch staff create/edit forms. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <x-form.input name="name" :label="__('Name')" required maxlength="150" :value="old('name', $user->name ?? '')" />
    <x-form.input name="email" :label="__('Email')" type="email" required maxlength="255" :value="old('email', $user->email ?? '')" />
    <x-form.input name="phone" :label="__('Phone')" maxlength="30" :value="old('phone', $user->phone ?? '')" />
    <x-form.select
        name="role"
        :label="__('Role')"
        required
        :options="$staffRoles->mapWithKeys(fn ($r) => [$r->name => $r->display_name])->all()"
        :value="old('role', $initialRole)"
        data-role-select
    />
    <x-form.select
        name="status"
        :label="__('Status')"
        required
        :options="['active' => __('Active'), 'inactive' => __('Inactive')]"
        :value="old('status', isset($user) ? $user->status->value : 'active')"
    />
</div>

@if(! isset($user))
    <div data-field>
        <p class="font-label-caps text-on-surface mb-2">{{ __('Password') }}</p>
        <div class="flex flex-col gap-2 text-body-sm">
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="password_mode" value="auto" class="text-primary focus:ring-primary" @checked(old('password_mode', 'auto') === 'auto')>
                {{ __('Auto-generate a secure password') }}
            </label>
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="password_mode" value="manual" class="text-primary focus:ring-primary" @checked(old('password_mode') === 'manual')>
                {{ __('Set password manually') }}
            </label>
        </div>
    </div>

    <div id="manual-password-fields" class="grid grid-cols-1 sm:grid-cols-2 gap-3 {{ old('password_mode') === 'manual' ? '' : 'hidden' }}">
        <x-form.input name="password" :label="__('Password')" type="password" minlength="{{ \App\Support\Settings\PasswordRules::minLength() }}" autocomplete="new-password" />
        <p class="form-help">{{ \App\Support\Settings\PasswordRules::helpText() }}</p>
        <x-form.input name="password_confirmation" :label="__('Confirm Password')" type="password" minlength="{{ \App\Support\Settings\PasswordRules::minLength() }}" data-match="#password" autocomplete="new-password" />
    </div>
@endif

<x-permission-grid
    :permissions-by-module="$permissionsByModule"
    :role-defaults-map="$roleDefaultsMap"
    :selected-permissions="$selectedPermissions"
    :disabled-permissions="$disabledPermissions"
    :initial-role="$initialRole"
/>
