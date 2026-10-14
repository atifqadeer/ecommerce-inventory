<?php

namespace App\Services\Shopify;

use App\Models\Channel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Shopify Admin GraphQL API.
 *
 * A channel authenticates either with an Admin API access token (custom app, never expires) or with a
 * Dev Dashboard app's client ID and secret, which are exchanged for a 24-hour token via the client
 * credentials grant and refreshed automatically.
 */
class ShopifyClient
{
    public const REQUIRED_SCOPES = ['read_products', 'read_inventory', 'read_orders'];

    public function __construct(private Channel $channel) {}

    /** "https://Store.myshopify.com/admin" or "store" → "store.myshopify.com"; null when it isn't a myshopify domain. */
    public static function normalizeDomain(string $input): ?string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^https?://#', '', $host);
        $host = explode('/', $host)[0];

        if (! str_contains($host, '.')) {
            $host .= '.myshopify.com';
        }

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $host) ? $host : null;
    }

    /**
     * Exchange a Dev Dashboard app's client ID and secret for an access token.
     *
     * @return array{access_token: string, expires_at: \Illuminate\Support\Carbon}
     */
    public static function requestClientCredentialsToken(string $domain, string $clientId, string $clientSecret): array
    {
        try {
            $response = self::http($domain)->asForm()->timeout(30)->post("https://{$domain}/admin/oauth/access_token", [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (ConnectionException) {
            throw new ShopifyException("Couldn't reach {$domain}. Check the store URL and your internet connection.");
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            $detail = $response->json('error_description') ?? $response->json('error') ?? $response->json('errors') ?? "HTTP {$response->status()}";
            throw new ShopifyException('Shopify rejected the client ID / secret ('.(is_string($detail) ? $detail : json_encode($detail)).'). '
                .'The app must be installed on this store and belong to the same Shopify organization.', authFailed: true);
        }

        return [
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 86399)),
        ];
    }

    private static function http(string $domain): PendingRequest
    {
        $request = Http::acceptJson();
        $ip = config('services.shopify.resolve_ip');

        return $ip ? $request->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$domain}:443:{$ip}"]]]) : $request;
    }

    /** Run a GraphQL query and return its "data". Retries when Shopify throttles the request. */
    public function query(string $query, array $variables = []): array
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->send($query, $variables);
            $errors = $response->json('errors');

            $throttled = $response->status() === 429
                || (is_array($errors) && collect($errors)->contains(fn ($e) => ($e['extensions']['code'] ?? null) === 'THROTTLED'));
            if ($throttled && $attempt < 5) {
                sleep(2 * $attempt);

                continue;
            }

            $this->guard($response);

            return $response->json('data') ?? [];
        }
    }

    private function send(string $query, array $variables): Response
    {
        $domain = $this->channel->handle;
        $version = config('services.shopify.api_version');

        try {
            return self::http($domain)->withHeaders(['X-Shopify-Access-Token' => $this->token()])
                ->timeout(60)
                ->post("https://{$domain}/admin/api/{$version}/graphql.json", [
                    'query' => $query,
                    'variables' => (object) $variables,
                ]);
        } catch (ConnectionException) {
            throw new ShopifyException("Couldn't reach {$domain}. Check your internet connection and try again.");
        }
    }

    /** A valid access token, refreshing a client-credentials token that is about to expire. */
    private function token(): string
    {
        $credentials = $this->channel->credentials ?? [];

        if (($credentials['type'] ?? null) === 'client_credentials'
            && (empty($credentials['access_token']) || ! $this->channel->token_expires_at || $this->channel->token_expires_at->subMinutes(5)->isPast())) {
            $token = self::requestClientCredentialsToken($this->channel->handle, $credentials['client_id'], $credentials['client_secret']);
            $credentials['access_token'] = $token['access_token'];
            $this->channel->fill(['credentials' => $credentials, 'token_expires_at' => $token['expires_at']]);
            if ($this->channel->exists) {
                $this->channel->save();
            }
        }

        if (empty($credentials['access_token'])) {
            throw new ShopifyException('No Shopify access token is saved for this channel. Update its API credentials.', authFailed: true);
        }

        return $credentials['access_token'];
    }

    private function guard(Response $response): void
    {
        $status = $response->status();

        if ($status === 401) {
            throw new ShopifyException('Shopify rejected the access token. It may have been revoked or the app uninstalled. Update the API credentials.', authFailed: true);
        }
        if ($status === 402) {
            throw new ShopifyException('This Shopify store is frozen or its plan has lapsed, so its API is unavailable.');
        }
        if ($status === 403) {
            throw new ShopifyException('The Shopify app is missing permissions. Grant these Admin API scopes: '.implode(', ', self::REQUIRED_SCOPES).'.', authFailed: true);
        }
        if ($status === 404) {
            // Shopify answers 404 (not 401) when a token doesn't belong to the store.
            throw new ShopifyException("Shopify didn't accept this access token for {$this->channel->handle}. Check the token was created for this store.", authFailed: true);
        }
        if (! $response->successful()) {
            throw new ShopifyException("Shopify returned HTTP {$status}. Try again in a few minutes.");
        }

        $errors = $response->json('errors');
        if ($errors) {
            $message = is_array($errors) ? collect($errors)->map(fn ($e) => is_array($e) ? ($e['message'] ?? json_encode($e)) : $e)->implode(' ') : (string) $errors;
            throw new ShopifyException('Shopify API error: '.$message);
        }
    }
}
