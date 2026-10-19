{{-- Shared customer form fields (create + edit). --}}
<div class="form-grid form-grid--3">
    <x-form.input name="name" :label="__('Full name')" maxlength="150" :value="old('name', $customer->name ?? '')" required />
    <x-form.input name="phone" :label="__('Phone')" maxlength="20" :value="old('phone', $customer->phone ?? '')" required />
    <x-form.input name="alternate_phone" :label="__('Alternate phone')" maxlength="20" :value="old('alternate_phone', $customer->alternate_phone ?? '')" />
    <x-form.input name="email" :label="__('Email')" type="email" maxlength="150" :value="old('email', $customer->email ?? '')" />
    <x-form.input name="nie" :label="__('NIE / NIF')" maxlength="20" :value="old('nie', $customer->nie ?? '')" />
    <x-form.input name="city" :label="__('City')" maxlength="100" :value="old('city', $customer->city ?? '')" />

    <div class="form-grid__full">
        <x-form.textarea name="address" :label="__('Address')" rows="2" maxlength="500" :value="old('address', $customer->address ?? '')" />
    </div>
    <div class="form-grid__full">
        <x-form.textarea name="notes" :label="__('Internal notes')" rows="2" maxlength="1000" :value="old('notes', $customer->notes ?? '')" />
    </div>
</div>
