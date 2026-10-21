<?php

namespace App\Services;

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivAuthException;
use App\Exceptions\DerivRateLimitedException;
use App\Models\DerivConnection;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/** Validates a Personal Access Token against Deriv's API and stores it as the user's connection. */
class DerivPatConnector
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public const REJECTED_MESSAGE = 'Deriv did not accept this token. Check it and try again — tokens made for Deriv\'s old API no longer work here.';

    public function __construct(private readonly DerivApiService $derivApi) {}

    /** Seconds until the user may try another token; 0 when they may now. */
    public function availableIn(User $user): int
    {
        $key = $this->limiterKey($user);

        return RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS) ? max(1, RateLimiter::availableIn($key)) : 0;
    }

    /**
     * Counts as one attempt against the user's limit whatever the outcome.
     *
     * @throws DerivRateLimitedException When Deriv's shared request budget is spent.
     * @throws DerivAuthException When Deriv rejects the token.
     * @throws DerivApiException When Deriv cannot be reached or reports another error.
     */
    public function connect(User $user, string $token): DerivConnection
    {
        RateLimiter::hit($this->limiterKey($user), self::DECAY_SECONDS);

        try {
            $response = $this->derivApi->checkPat($token);
        } catch (DerivRateLimitedException $e) {
            throw $e;
        } catch (DerivApiException) {
            throw new DerivApiException('Could not reach Deriv. Please check your connection and try again.');
        }

        if ($response->status() === 401) {
            throw new DerivAuthException(self::REJECTED_MESSAGE);
        }

        if ($response->failed()) {
            throw new DerivApiException($response->json('errors.0.message') ?? 'Could not verify your token. Please try again.');
        }

        $connection = DerivConnection::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token' => $token,
                'refresh_token' => null,
                'token_type' => 'pat',
                'expires_at' => null,
                'scope' => null,
            ]
        );

        $this->derivApi->forgetConnection($connection);

        return $connection;
    }

    private function limiterKey(User $user): string
    {
        return "deriv-pat-connect:{$user->id}";
    }
}
