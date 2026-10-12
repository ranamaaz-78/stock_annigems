{{--
    Compact settings switch. Hidden 0 + checkbox 1 so an unchecked box still
    posts a real boolean (the Form Request requires one).
--}}
@props([
    'name',
    'checked' => false,
    'disabled' => false,
])

<label class="toggle-switch">
    @unless($disabled)
        <input type="hidden" name="{{ $name }}" value="0">
    @endunless
    <input
        type="checkbox"
        name="{{ $name }}"
        value="1"
        class="toggle-switch__input"
        data-settings-toggle="{{ $name }}"
        @checked($checked)
        @disabled($disabled)
    >
    <span class="toggle-switch__slider" aria-hidden="true"></span>
</label>
