<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Honeypot: bots fill this field, real users never see it
        if (! empty($input['website'])) {
            // Silently pretend success without creating a user
            return new User;
        }

        // Registration rate limiting: 5 per IP per minute
        $throttleKey = 'register|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many registration attempts. Please try again in a minute.'],
            ]);
        }

        RateLimiter::hit($throttleKey, 60);

        // Names are stored as typed; the name rule rejects markup and Blade escapes on output.
        $input['name'] = trim($input['name'] ?? '');
        $input['email'] = strtolower(trim($input['email'] ?? ''));

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'account_no' => 'ACC'.strtoupper(Str::random(8)),
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        Mail::to($user)->send(new WelcomeMail($user));

        return $user;
    }
}
