{{-- Browser tab icon: the bundled AG monogram (public/brand/). --}}
@php($v = @filemtime(public_path('brand/favicon-32.png')) ?: 1)
<link rel="icon" href="{{ asset('brand/favicon-32.png') }}?v={{ $v }}" type="image/png" sizes="32x32">
<link rel="icon" href="{{ asset('brand/favicon-64.png') }}?v={{ $v }}" type="image/png" sizes="64x64">
<link rel="shortcut icon" href="{{ asset('brand/favicon-32.png') }}?v={{ $v }}">
<link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon.png') }}?v={{ $v }}">
