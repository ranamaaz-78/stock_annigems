{{-- Shared creditor form fields (create + edit). --}}
<div class="form-grid form-grid--3">
    <x-form.input name="name" label="Full name" maxlength="150" :value="old('name', $creditor->name ?? '')" required />
    <x-form.input name="phone" label="Phone" maxlength="30" :value="old('phone', $creditor->phone ?? '')" required />
    <x-form.input name="alternate_phone" label="Alternate phone" maxlength="30" :value="old('alternate_phone', $creditor->alternate_phone ?? '')" />
    <x-form.input name="email" label="Email" type="email" maxlength="150" :value="old('email', $creditor->email ?? '')" />
    <x-form.input name="nie" label="NIE / NIF" help="optional" maxlength="20" :value="old('nie', $creditor->nie ?? '')" />
    <x-form.input name="city" label="City" maxlength="100" :value="old('city', $creditor->city ?? '')" />

    <div class="form-grid__full">
        <x-form.textarea name="address" label="Address" rows="2" maxlength="2000" :value="old('address', $creditor->address ?? '')" />
    </div>
    <div class="form-grid__full">
        <x-form.textarea name="notes" label="Internal notes" rows="2" maxlength="5000" :value="old('notes', $creditor->notes ?? '')" />
    </div>
</div>
