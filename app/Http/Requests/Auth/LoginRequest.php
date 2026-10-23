<?php

namespace App\Http\Requests\Auth;

use App\Support\AccountLoginThrottle;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            AccountLoginThrottle::hit($this->string('email'));

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if (Auth::user()->isSuspended()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'Your account has been suspended. Contact your administrator.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        AccountLoginThrottle::clear($this->string('email'));
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $perIp = RateLimiter::tooManyAttempts($this->throttleKey(), 5);
        if (! $perIp && ! AccountLoginThrottle::tooManyAttempts($this->string('email'))) {
            return;
        }

        event(new Lockout($this));

        $seconds = $perIp ? RateLimiter::availableIn($this->throttleKey()) : AccountLoginThrottle::availableIn($this->string('email'));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
