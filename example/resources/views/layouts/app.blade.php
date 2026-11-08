<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800;900&family=Rajdhani:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="/css/auth.css">
    @include('partials._theme')
</head>
<body>
    <header class="site-header">
        <a class="brand" href="{{ route('home') }}"><img src="/images/logo-icon.svg" alt="" width="32" height="32">{{ config('app.name') }}</a>
        <nav aria-label="Site navigation"><a href="{{ route('welcome') }}">About</a>@auth <a href="/app/dashboard">Open app</a> @else <a href="{{ route('login') }}">Login</a> @endauth</nav>
    </header>
    <main class="auth-content">
        @if(session('success'))<p class="notice" role="status">{{ session('success') }}</p>@endif
        @if(session('error'))<p class="notice error" role="alert">{{ session('error') }}</p>@endif
        @if($errors->any())<div class="notice error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @yield('content')
    </main>
    @include('partials._analytics')
    @include('partials._cookie-consent')
</body>
</html>
