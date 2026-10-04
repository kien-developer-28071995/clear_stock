@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
    <div class="card login">
        <h1>{{ config('report.app_name') }} Admin</h1>
        <p class="sub">Owner reports. Sign in to continue.</p>
        <form method="post" action="{{ url('/login') }}">
            @csrf
            <label>Email <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
            <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
            @error('email') <p class="error">{{ $message }}</p> @enderror
            <button>Sign in</button>
        </form>
    </div>
@endsection
