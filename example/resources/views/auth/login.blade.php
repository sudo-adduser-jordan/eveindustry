@extends('layouts.app')
@section('title', 'Login')
@section('content')
<section class="auth-panel narrow">
    <h1>Login</h1>
    <p>Login securely with your EVE Online account.</p>
    <a class="button primary" href="{{ route('auth.eve.scopes') }}">Login with EVE Online</a>
    <p class="muted">Review your permissions before continuing to the official EVE SSO page.</p>
    @if(config('demo.enabled'))
    <form method="POST" action="{{ route('demo.login') }}">
        @csrf
        <button type="submit" class="button">Try the demo</button>
    </form>
    <p class="muted">Explore with three generated characters. No EVE login needed; data resets daily.</p>
    @endif
</section>
@endsection
