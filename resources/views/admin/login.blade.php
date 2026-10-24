@extends('admin.layout')

@section('title', 'Sign in')

@section('content')
    <div style="max-width: 380px; margin: 8vh auto 0;">
        <div style="text-align: center; margin-bottom: 20px;">
            <div class="brand-mark" style="width: 52px; height: 52px; border-radius: 14px; font-size: 24px; margin: 0 auto 12px; font-weight: 800;">L</div>
            <h1>Librum Admin</h1>
            <p class="subtitle">For the Librum team only.</p>
        </div>
        <form class="card" method="post" action="{{ route('admin.login.submit') }}">
            @csrf
            <div style="margin-bottom: 14px;">
                <label for="phone">Phone number</label>
                <input id="phone" name="phone" type="tel" inputmode="numeric" autocomplete="username" value="{{ old('phone') }}" required autofocus>
            </div>
            <div style="margin-bottom: 18px;">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="btn" type="submit" style="width: 100%;">Sign in</button>
        </form>
    </div>
@endsection
