<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

class GoogleLinkController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->redirectUrl(route('auth.google.link.callback'))
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')
            ->redirectUrl(route('auth.google.link.callback'))
            ->user();

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
