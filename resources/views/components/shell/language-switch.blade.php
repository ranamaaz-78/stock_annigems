{{--
    Language button: ES | EN.

    Each language is its own tiny form posting to locale.switch, so it works
    without JavaScript and before sign-in. The current language is marked and
    not clickable. tone: light (topbar, login card) | dark (sidebar).
--}}
@props(['tone' => 'light'])

@php
    $current = app()->getLocale();
    $locales = (array) config('app.supported_locales', []);
@endphp

<div {{ $attributes->merge(['class' => 'lang-switch lang-switch--'.$tone]) }} role="group" aria-label="{{ __('Language') }}">
    @foreach($locales as $code => $name)
        @if($code === $current)
            <span class="lang-switch__option is-on" aria-current="true" title="{{ $name }}">{{ strtoupper($code) }}</span>
        @else
            <form method="POST" action="{{ route('locale.switch', $code) }}" class="lang-switch__form">
                @csrf
                <button type="submit" class="lang-switch__option" title="{{ $name }}" lang="{{ $code }}">{{ strtoupper($code) }}</button>
            </form>
        @endif
    @endforeach
</div>
