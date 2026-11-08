@php($__theme = app(\App\Services\ThemeService::class)->active())
@if($__theme)
    <link rel="stylesheet" id="theme-css" href="{{ $__theme['css_url'] }}">
    @if($__theme['theme_color'])<meta name="theme-color" content="{{ $__theme['theme_color'] }}">@endif
@endif
