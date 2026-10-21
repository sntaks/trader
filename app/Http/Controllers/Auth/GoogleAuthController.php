<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleAuthController extends Controller
{
    public const CANCELLED_MESSAGE = 'Google sign-in was cancelled.';

    public const FAILED_MESSAGE = 'Google sign-in did not complete. Please try again.';

    public const UNVERIFIED_MESSAGE = 'Google has not verified this email address yet. Verify it with Google, or sign in with your password.';

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->failed(self::CANCELLED_MESSAGE);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException|GuzzleException $e) {
            Log::warning('Google sign-in failed: '.$e::class.' — '.$e->getMessage());

            return $this->failed(self::FAILED_MESSAGE);
        }

        $googleEmail = strtolower(trim($googleUser->getEmail() ?? ''));
        $googleName = $this->displayName($googleUser, $googleEmail);

        $user = User::where('google_id', $googleUser->getId())->first();

        if ($user) {
            return $this->logIn($request, $user);
        }

        if (($googleUser->getRaw()['email_verified'] ?? false) !== true) {
            return $this->failed(self::UNVERIFIED_MESSAGE);
        }

        $user = User::where('email', $googleEmail)->first();

        if ($user) {
            if (is_null($user->email_verified_at)) {
                $user->secureAfterTakeover();
                $user->email_verified_at = now();
            }

            $user->google_id = $googleUser->getId();
            $user->avatar = $googleUser->getAvatar();

            $user->save();

            return $this->logIn($request, $user);
        }

        $user = User::create([
            'account_no' => $this->generateAccountNumber(),
            'name' => $googleName,
            'email' => $googleEmail,
            'google_id' => $googleUser->getId(),
            'avatar' => $googleUser->getAvatar(),
            'password' => null,
        ]);

        $user->email_verified_at = now();
        $user->save();

        Mail::to($user)->send(new WelcomeMail($user));

        return $this->logIn($request, $user);
    }

    /**
     * Log the user in, or hand them to Fortify's two-factor challenge when they have confirmed two-factor:
     * Google proves the mailbox, not the second factor.
     */
    private function logIn(Request $request, User $user): RedirectResponse
    {
        if ($user->two_factor_confirmed_at) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    /** Send a failed sign-in back with its message: to the login page, or to the dashboard for someone already signed in. */
    private function failed(string $message): RedirectResponse
    {
        return auth()->check()
            ? redirect()->route('dashboard')->with('error', $message)
            : redirect()->route('login')->withErrors(['google' => $message]);
    }

    /**
     * Google's name as given, trimmed and capped at 100 characters; the email's local part when it is blank.
     * Stored raw: Blade escapes it on output.
     */
    private function displayName(SocialiteUser $googleUser, string $email): string
    {
        $name = trim((string) $googleUser->getName());

        return $name !== '' ? mb_substr($name, 0, 100) : Str::before($email, '@');
    }

    private function generateAccountNumber(): string
    {
        do {
            $accountNo = 'G'.strtoupper(Str::random(8));
        } while (User::where('account_no', $accountNo)->exists());

        return $accountNo;
    }
}
