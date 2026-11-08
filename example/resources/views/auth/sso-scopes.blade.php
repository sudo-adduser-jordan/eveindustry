@extends('layouts.app')
@section('title', 'Authorize Access — EVE SSO')
@section('content')
<section class="auth-panel">
    <h1>Authorize {{ config('app.name') }}</h1>
    <p>Review the permissions that will be requested through EVE SSO before continuing.</p>
    <form method="GET" action="{{ route('auth.eve') }}">
        <input type="hidden" name="scopes_configured" value="1">
        <h2>Required Permissions</h2>
        <p class="muted">These permissions are always requested for core functionality.</p>
        <ul class="scope-list">
            @foreach($requiredScopes as $scope)
            <li><strong>{{ $scope['label'] }}</strong><p>{{ $scope['description'] }}</p></li>
            @endforeach
        </ul>
        <fieldset>
            <legend>Optional Permissions</legend>
            <p class="muted">All optional permissions are selected by default. Uncheck any you do not wish to grant. Features needing unchecked permissions will be unavailable.</p>
            @foreach($optionalScopes as $opt)
            <label class="scope-choice"><input type="checkbox" name="optional_scopes[]" value="{{ $opt['scope'] }}" checked><span><strong>{{ $opt['label'] }}</strong><span class="scope-description">{{ $opt['description'] }}</span></span></label>
            @endforeach
        </fieldset>
        <button type="submit" class="button primary">Continue to EVE SSO</button>
    </form>
    <p class="muted">You will be redirected to the official EVE Online SSO page.</p>
</section>
@endsection
