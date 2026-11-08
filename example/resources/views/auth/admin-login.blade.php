@extends('layouts.app')
@section('title', 'Admin Login')
@section('content')
<section class="auth-panel narrow">
    <h1>Admin Login</h1>
    <form method="POST" action="{{ route('admin.login.submit') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <label class="choice"><input type="checkbox" name="remember" id="remember">Remember me</label>
        <button class="button primary" type="submit">Login</button>
    </form>
    <p><a href="{{ route('login') }}">Back to login</a></p>
</section>
@endsection
