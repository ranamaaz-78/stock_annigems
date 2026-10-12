{{-- Shared customer form fields (create + edit). --}}
<div class="form-grid form-grid--3">
    <x-form.input name="name" label="Full name" maxlength="150" :value="old('name', $customer->name ?? '')" required />
    <x-form.input name="phone" label="Phone" maxlength="20" :value="old('phone', $customer->phone ?? '')" required />
    <x-form.input name="alternate_phone" label="Alternate phone" maxlength="20" :value="old('alternate_phone', $customer->alternate_phone ?? '')" />
    <x-form.input name="email" label="Email" type="email" maxlength="150" :value="old('email', $customer->email ?? '')" />
    <x-form.input name="cnic" label="CNIC" placeholder="12345-1234567-1" maxlength="15" :value="old('cnic', $customer->cnic ?? '')" />
    <x-form.input name="city" label="City" maxlength="100" :value="old('city', $customer->city ?? '')" />

    <div class="form-grid__full">
        <x-form.textarea name="address" label="Address" rows="2" maxlength="500" :value="old('address', $customer->address ?? '')" />
    </div>
    <div class="form-grid__full">
        <x-form.textarea name="notes" label="Internal notes" rows="2" maxlength="1000" :value="old('notes', $customer->notes ?? '')" />
    </div>
</div>
