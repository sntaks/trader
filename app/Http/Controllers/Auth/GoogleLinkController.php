<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleLinkController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->redirectUrl(route('auth.google.link.callback'))
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('profile.edit')->withErrors(['google' => GoogleAuthController::CANCELLED_MESSAGE]);
        }

        try {
            $googleUser = Socialite::driver('google')
                ->redirectUrl(route('auth.google.link.callback'))
                ->user();
        } catch (InvalidStateException|GuzzleException $e) {
            Log::warning('Google account link failed: '.$e::class.' — '.$e->getMessage());

            return redirect()->route('profile.edit')->withErrors(['google' => GoogleAuthController::FAILED_MESSAGE]);
        }
        $conflict = User::where('google_id', $googleUser->getId())
            ->where('id', '!=', Auth::id())
            ->exists();

        if ($conflict) {
            return redirect()->route('profile.edit')
                ->withErrors(['google' => 'This Google account is already linked to another user.']);
        }

        $user = Auth::user();
        $user->google_id = $googleUser->getId();
        $user->avatar = $googleUser->getAvatar();

        if (is_null($user->email_verified_at) && $this->provesEmail($googleUser, $user)) {
            $user->email_verified_at = now();
        }

        $user->save();

        return redirect()->route('profile.edit')
            ->with('status', 'google-linked');
    }

    /** Whether Google has verified the same address the user registered with. */
    private function provesEmail(SocialiteUser $googleUser, User $user): bool
    {
        return ($googleUser->getRaw()['email_verified'] ?? false) === true
            && strcasecmp(trim((string) $googleUser->getEmail()), $user->email) === 0;
    }
}
