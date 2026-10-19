{{-- Shared creditor form fields (create + edit). --}}
<div class="form-grid form-grid--3">
    <x-form.input name="name" :label="__('Full name')" maxlength="150" :value="old('name', $creditor->name ?? '')" required />
    <x-form.input name="phone" :label="__('Phone')" maxlength="30" :value="old('phone', $creditor->phone ?? '')" required />
    <x-form.input name="alternate_phone" :label="__('Alternate phone')" maxlength="30" :value="old('alternate_phone', $creditor->alternate_phone ?? '')" />
    <x-form.input name="email" :label="__('Email')" type="email" maxlength="150" :value="old('email', $creditor->email ?? '')" />
    <x-form.input name="nie" :label="__('NIE / NIF')" maxlength="20" :value="old('nie', $creditor->nie ?? '')" />
    <x-form.input name="city" :label="__('City')" maxlength="100" :value="old('city', $creditor->city ?? '')" />

    <div class="form-grid__full">
        <x-form.textarea name="address" :label="__('Address')" rows="2" maxlength="2000" :value="old('address', $creditor->address ?? '')" />
    </div>
    <div class="form-grid__full">
        <x-form.textarea name="notes" :label="__('Internal notes')" rows="2" maxlength="5000" :value="old('notes', $creditor->notes ?? '')" />
    </div>
</div>
