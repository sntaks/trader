<?php

namespace App\Http\Controllers;

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivRateLimitedException;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\DerivTokenRefresher;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DerivOAuthController extends Controller
{
    private const AUTH_URL = 'https://auth.deriv.com/oauth2/auth';

    /**
     * Redirect user to Deriv OAuth authorization page with PKCE.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state = Str::random(40);

        $request->session()->put('deriv_oauth_state', $state);
        $request->session()->put('deriv_code_verifier', $codeVerifier);

        $url = self::AUTH_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('deriv.app_id'),
            'redirect_uri' => config('deriv.redirect_uri'),
            'scope' => config('deriv.oauth_scopes'),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect()->away($url);
    }

    /**
     * Handle the OAuth callback — verify state, exchange code for token.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('dashboard')
                ->with('error', 'Deriv authorization was denied: '.$request->input('error_description', $request->input('error')));
        }

        $state = $request->session()->pull('deriv_oauth_state');
        $codeVerifier = $request->session()->pull('deriv_code_verifier');

        if (! $state || ! hash_equals($state, (string) $request->input('state', ''))) {
            return redirect()->route('dashboard')
                ->with('error', 'Invalid OAuth state. Please try connecting again.');
        }

        $code = $request->input('code');

        if (! $code) {
            return redirect()->route('dashboard')
                ->with('error', 'No authorization code received. Please try again.');
        }

        try {
            $response = Http::asForm()->post(DerivTokenRefresher::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'client_id' => config('deriv.app_id'),
                'code' => $code,
                'code_verifier' => $codeVerifier,
                'redirect_uri' => config('deriv.redirect_uri'),
            ])->throw()->json();
        } catch (RequestException $e) {
            return redirect()->route('dashboard')
                ->with('error', 'Failed to exchange authorization code. Please try again.');
        }

        DerivConnection::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? null,
                'token_type' => $response['token_type'] ?? 'Bearer',
                'expires_at' => isset($response['expires_in'])
                    ? now()->addSeconds((int) $response['expires_in'])
                    : null,
                'scope' => $response['scope'] ?? null,
            ]
        );

        return redirect()->route('dashboard')
            ->with('success', 'Deriv account connected successfully.');
    }

    /**
     * Store a Personal Access Token (PAT) as a Deriv connection.
     * Validates the token against the Deriv REST API before saving.
     */
    public function connectPat(Request $request, DerivApiService $derivApi): RedirectResponse
    {
        $validated = $request->validate([
            'pat_token' => ['required', 'string', 'min:10'],
            'redirect_to' => ['nullable', 'string', 'in:account,copy-trading'],
        ]);

        $token = $validated['pat_token'];
        $redirectTo = $validated['redirect_to'] ?? 'account';

        try {
            $response = $derivApi->checkPat($token);
        } catch (DerivRateLimitedException) {
            return redirect()->route($redirectTo)
                ->with('error', 'Deriv is busy right now — try again in a minute.');
        } catch (DerivApiException) {
            return redirect()->route($redirectTo)
                ->with('error', 'Could not reach Deriv. Please try again.');
        }

        if ($response->status() === 401) {
            return redirect()->route($redirectTo)
                ->with('error', 'Invalid token. Please check your Personal Access Token and try again.');
        }

        if ($response->failed()) {
            $msg = $response->json('errors.0.message', 'Could not verify your token. Please try again.');

            return redirect()->route($redirectTo)->with('error', $msg);
        }

        DerivConnection::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'access_token' => $token,
                'refresh_token' => null,
                'token_type' => 'pat',
                'expires_at' => null,
                'scope' => null,
            ]
        );

        return redirect()->route($redirectTo)
            ->with('success', 'Deriv account connected via Personal Access Token.');
    }

    /**
     * Disconnect the user's Deriv account.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        $request->user()->derivConnection()->delete();

        return redirect()->route('dashboard')
            ->with('success', 'Deriv account disconnected.');
    }
}
