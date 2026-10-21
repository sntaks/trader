<?php

namespace App\Http\Controllers;

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivRateLimitedException;
use App\Http\Requests\ConnectPatRequest;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\DerivPatConnector;
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
     * Redirect user to Deriv OAuth authorization page with PKCE. `?signup=1` opens Deriv's sign-up form instead
     * of its login form, so a new Deriv account is tagged with the affiliate token.
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
            ...$this->affiliateParameters(),
            ...($request->query('signup') === '1' ? ['prompt' => 'registration'] : []),
        ]);

        return redirect()->away($url);
    }

    /**
     * Deriv's affiliate tracking parameters, sent only when an affiliate token is configured.
     *
     * @return array<string, string>
     */
    private function affiliateParameters(): array
    {
        $token = (string) config('deriv.affiliate_token');

        if ($token === '') {
            return [];
        }

        return array_filter([
            'affiliate_token' => $token,
            'utm_medium' => 'affiliate',
            'utm_campaign' => (string) config('deriv.utm_campaign'),
            'utm_source' => (string) config('deriv.affiliate_id'),
        ], fn (string $value) => $value !== '');
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

        $connection = DerivConnection::updateOrCreate(
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

        app(DerivApiService::class)->forgetConnection($connection);

        if (! $connection->canRunUnattended()) {
            CopySetting::query()
                ->where('user_id', $connection->user_id)
                ->where('is_running', true)
                ->update(['is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_REAUTH]);
        }

        return redirect()->route('dashboard')
            ->with('success', 'Deriv account connected successfully.');
    }

    /**
     * Store a Personal Access Token (PAT) as a Deriv connection once Deriv accepts it.
     */
    public function connectPat(ConnectPatRequest $request, DerivPatConnector $connector): RedirectResponse
    {
        $redirectTo = $request->validated('redirect_to') ?? 'account';

        if (($wait = $connector->availableIn($request->user())) > 0) {
            return redirect()->route($redirectTo)
                ->with('error', "Too many attempts — try again in {$wait} seconds.");
        }

        try {
            $connector->connect($request->user(), $request->validated('pat_token'));
        } catch (DerivRateLimitedException) {
            return redirect()->route($redirectTo)
                ->with('error', 'Deriv is busy right now — try again in a minute.');
        } catch (DerivApiException $e) {
            return redirect()->route($redirectTo)->with('error', $e->getMessage());
        }

        return redirect()->route($redirectTo)
            ->with('success', 'Deriv account connected via Personal Access Token.');
    }

    /**
     * Disconnect the user's Deriv account.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        $request->user()->derivConnection?->disconnect();

        return redirect()->route('dashboard')
            ->with('success', 'Deriv account disconnected. To revoke access completely, also delete the token in your Deriv account settings.');
    }
}
