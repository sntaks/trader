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
            return redirect()->route('login')->withErrors(['google' => self::CANCELLED_MESSAGE]);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException|GuzzleException $e) {
            Log::warning('Google sign-in failed: '.$e::class.' — '.$e->getMessage());

            return redirect()->route('login')->withErrors(['google' => self::FAILED_MESSAGE]);
        }

        $googleEmail = strtolower(trim($googleUser->getEmail() ?? ''));
        $googleName = $this->displayName($googleUser, $googleEmail);

        $user = User::where('google_id', $googleUser->getId())->first();

        if ($user) {
            Auth::login($user, remember: true);

            return redirect()->intended(route('dashboard'));
        }

        if (($googleUser->getRaw()['email_verified'] ?? false) !== true) {
            return redirect()->route('login')->withErrors(['google' => self::UNVERIFIED_MESSAGE]);
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

            Auth::login($user, remember: true);

            return redirect()->intended(route('dashboard'));
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

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Google's name as given, trimmed and capped at 100 characters; the email's local part when it is blank.
     * Stored raw: Blade escapes it on output.
     */
    private function displayName(SocialiteUser $googleUser, string $email): string
    {
        $name = trim((string) $googleUser->getName());

        return $name !== '' ? Str::limit($name, 100, '') : Str::before($email, '@');
    }

    private function generateAccountNumber(): string
    {
        do {
            $accountNo = 'G'.strtoupper(Str::random(8));
        } while (User::where('account_no', $accountNo)->exists());

        return $accountNo;
    }
}
