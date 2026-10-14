<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\SyncLog;
use App\Services\Ledger;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyException;
use App\Services\Shopify\ShopifySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChannelController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->channels()->orderBy('platform')->orderBy('id')
            ->withCount(['orders as open_orders_count' => fn ($q) => $q->whereIn('status', ['paid', 'processing'])])
            ->withCount('orders')
            ->get();
    }

    /**
     * Add a store. "api" stores (Shopify) are synced from the platform's API; "statements" stores
     * (any platform) are kept up to date by uploading reports.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'platform' => ['required', Rule::in(array_keys(Channel::SUPPORTED))],
            'name' => ['required', 'string', 'max:100', Rule::unique('channels')->where('user_id', $user->id)],
            'connection' => ['required', Rule::in(['api', 'statements'])],
            'handle' => ['nullable', 'required_if:connection,api', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sync_frequency' => ['nullable', Rule::in(Channel::FREQUENCIES)],
        ], ['name.unique' => 'You already have a store with this name.']);

        $attributes = [
            'platform' => $data['platform'],
            'name' => $data['name'],
            'color' => Channel::SUPPORTED[$data['platform']],
            'handle' => trim($data['handle'] ?? ''),
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
            'status' => 'connected',
            'sync_frequency' => $data['sync_frequency'] ?? 'Hourly',
        ];

        if ($data['connection'] === 'api') {
            if (! in_array($data['platform'], Channel::INTEGRATED, true)) {
                throw ValidationException::withMessages(['connection' => "{$data['platform']} can't be connected by API yet. Choose statement uploads instead."]);
            }
            $attributes['handle'] = $this->shopifyDomain($data['handle']);
            if ($user->channels()->where('platform', $data['platform'])->where('handle', $attributes['handle'])->exists()) {
                throw ValidationException::withMessages(['handle' => "{$attributes['handle']} is already added."]);
            }
            $attributes += $this->verifiedCredentials($request, $attributes['handle'], required: true);
        }

        $channel = $user->channels()->create($attributes);
        Ledger::setupChartOfAccounts($user);
        Ledger::receivableAccountFor($channel);
        $logs = $channel->auth_type ? self::runSync($channel) : [];

        return response()->json(['channel' => $channel->fresh(), 'logs' => $logs], 201);
    }

    public function update(Request $request, int $id): Channel
    {
        $channel = $request->user()->channels()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('channels')->where('user_id', $channel->user_id)->ignore($channel->id)],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'sync_frequency' => ['sometimes', Rule::in(Channel::FREQUENCIES)],
        ], ['name.unique' => 'You already have a store with this name.']);

        if ($channel->auth_type) {
            unset($data['handle']); // an API store's address is fixed by its credentials
        } elseif (array_key_exists('handle', $data)) {
            $data['handle'] = trim($data['handle'] ?? '');
        }
        if (! empty($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        $channel->update($data);

        return $channel;
    }

    /** Pull the latest data from the platform's API. */
    public function sync(Request $request, int $id): JsonResponse
    {
        $channel = $request->user()->channels()->findOrFail($id);
        if (! $channel->auth_type) {
            throw ValidationException::withMessages(['channel' => "{$channel->name} is updated by uploading statements."]);
        }
        if ($channel->status === 'disconnected') {
            throw ValidationException::withMessages(['channel' => "Reconnect {$channel->name} before syncing."]);
        }

        $logs = self::runSync($channel);

        return response()->json(['channel' => $channel->fresh(), 'logs' => $logs]);
    }

    /** Reconnect a store, optionally with new API credentials (which also switches a statement store to API sync). */
    public function reauthorize(Request $request, int $id): JsonResponse
    {
        $channel = $request->user()->channels()->findOrFail($id);

        if ($channel->auth_type || $request->filled('auth_type')) {
            if (! in_array($channel->platform, Channel::INTEGRATED, true)) {
                throw ValidationException::withMessages(['auth_type' => "{$channel->platform} can't be connected by API yet."]);
            }
            $domain = $this->shopifyDomain($request->input('handle') ?: $channel->handle);
            $channel->update($this->verifiedCredentials($request, $domain, required: ! $channel->auth_type, current: $channel->credentials) + ['handle' => $domain, 'status' => 'connected']);
            $logs = self::runSync($channel);
        } else {
            $channel->update(['status' => 'connected']);
            $logs = [];
        }

        return response()->json(['channel' => $channel->fresh(), 'logs' => $logs]);
    }


    public function disconnect(Request $request, int $id): Channel
    {
        $channel = $request->user()->channels()->findOrFail($id);
        $channel->update(['status' => 'disconnected']);

        return $channel;
    }

    public function logs(Request $request)
    {
        return SyncLog::query()
            ->whereIn('channel_id', $request->user()->channels()->select('id'))
            ->when($request->filled('channel_id'), fn ($q) => $q->where('channel_id', $request->integer('channel_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('channel:id,name,platform,color')
            ->latest()->latest('id')
            ->limit($request->integer('limit', 25))
            ->get();
    }

    /** Pull live data for a store with API credentials. Statement stores are updated by uploads, so there is nothing to sync. */
    public static function runSync(Channel $channel): array
    {
        if (! $channel->auth_type) {
            return [];
        }

        try {
            return (new ShopifySync($channel))->run();
        } catch (ShopifyException $e) {
            if ($e->authFailed) {
                $channel->update(['status' => 'action_needed']);
            }

            return [$channel->syncLogs()->create(['job_type' => 'Orders sync', 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)])];
        }
    }

    private function shopifyDomain(?string $input): string
    {
        return ShopifyClient::normalizeDomain((string) $input)
            ?? throw ValidationException::withMessages(['handle' => "Enter the store's myshopify.com address, e.g. your-store.myshopify.com."]);
    }

    /**
     * Validate the Shopify credentials in the request and check they work against the store.
     * When not required and none are sent, the current credentials are re-checked (refreshing a client-credentials token).
     *
     * @return array{credentials: array, token_expires_at: mixed}
     */
    private function verifiedCredentials(Request $request, string $domain, bool $required, ?array $current = null): array
    {
        $data = $request->validate([
            'auth_type' => [$required ? 'required' : 'nullable', Rule::in(['access_token', 'client_credentials'])],
            'access_token' => ['required_if:auth_type,access_token', 'nullable', 'string', 'max:255'],
            'client_id' => ['required_if:auth_type,client_credentials', 'nullable', 'string', 'max:255'],
            'client_secret' => ['required_if:auth_type,client_credentials', 'nullable', 'string', 'max:255'],
        ]);

        $credentials = match ($data['auth_type'] ?? null) {
            'access_token' => ['type' => 'access_token', 'access_token' => trim($data['access_token'])],
            'client_credentials' => ['type' => 'client_credentials', 'client_id' => trim($data['client_id']), 'client_secret' => trim($data['client_secret'])],
            default => $current,
        };
        if (($credentials['type'] ?? null) === 'client_credentials') {
            unset($credentials['access_token']); // always fetch a fresh token with these credentials
        }

        // Check against an unsaved copy so bad credentials never replace working ones.
        $probe = new Channel(['handle' => $domain, 'credentials' => $credentials]);
        try {
            (new ShopifySync($probe))->shop();
        } catch (ShopifyException $e) {
            $field = ($credentials['type'] ?? null) === 'client_credentials' ? 'client_id' : 'access_token';
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        return ['credentials' => $probe->credentials, 'token_expires_at' => $probe->token_expires_at];
    }
}
