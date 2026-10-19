{{-- Company logo: the uploaded one, else the bundled AG monogram. --}}
@props(['large' => false])

<img class="pd__logo" src="{{ brand_logo_url() }}" alt="{{ setting('company_name') }}">
