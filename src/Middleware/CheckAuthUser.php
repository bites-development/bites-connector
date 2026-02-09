<?php

namespace Modules\BitesMiddleware\Middleware;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CheckAuthUser
{
    private static array $userColumnCache = [];

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $bearerToken = ($request->server('HTTP_AUTHORIZATION'));

        if (str_contains($request->server('REQUEST_URI'), '/login')) {
            $user = $this->loginUsingEmailAndPassword($request->input('email'), $request->input('password'));
            if($user) {
                Auth::login($user, true);
            }
            return $next($request);
        }

        if (empty($bearerToken)) {
            $bearerToken = $request->token;
        }

        if (!empty($bearerToken)) {
            $user = $this->getUserInfoFromBase($bearerToken);
            if (!empty($user->id)) {
                Auth::login($user, true);
            }
        }

        return $next($request);
    }

    private function getUserInfoFromBase($token)
    {
        $appPrefix = $this->resolveAppPrefix();
        $request = Http::withToken($token)->acceptJson();
        if ($appPrefix !== '') {
            $request = $request->withHeaders([
                'X-Bites-App' => $appPrefix,
            ]);
        }

        /** @var Response $response */
        $response = $request
            ->get(
            $this->middlewareBaseUrl() . '/api/v1/auth/me'
        );
        $responseUser = json_decode($response->getBody()->getContents());
        if(!isset($responseUser->email))
        {
            return null;
        }
        $user = User::where('email', $responseUser->email)->first();
        if(empty($user->id)){
           $user = $this->customRegister($responseUser->name,$responseUser->email);
        }
        return $this->syncPlanFromMiddleware($user, $responseUser);
    }

    private function loginUsingEmailAndPassword($email, $password): User|null
    {
        /** @var Response $response */
        $response = Http::acceptJson()->post(
            $this->middlewareBaseUrl() . '/api/v1/auth/login',
            [
                'email' => $email,
                'password' => $password
            ]
        );
        $user = json_decode($response->getBody()->getContents());
        if(!isset($user->access_token))
        {
            return null;
        }
        $user = $this->getUserInfoFromBase($user->access_token);

        return User::where('email', $user->email)->first();
    }

    private function customRegister($name,$email)
    {
        $user = User::create([
                                 'user_role' => 'general',
                                 'username' => rand(100000, 999999),
                                 'name' => $name,
                                 'email' => $email,
                                 'friends' => json_encode(array()),
                                 'followers' => json_encode(array()),
                                 'timezone' => null,
                                 'status' => 0,
                                 'lastActive' => Carbon::now(),
                                 'created_at' => time()
                             ]);

        event(new Registered($user));

        return $user->refresh();
    }

    private function syncPlanFromMiddleware(User $user, object $responseUser): User
    {
        $currentPlan = isset($responseUser->current_plan) && is_object($responseUser->current_plan)
            ? $responseUser->current_plan
            : null;

        $updates = [];

        if ($this->hasUserColumn('m_user_id') && empty($user->m_user_id) && !empty($responseUser->id)) {
            $updates['m_user_id'] = (int) $responseUser->id;
        }

        $appPlanId = $currentPlan->app_plan_id ?? $responseUser->active_plan ?? null;
        $expiresAt = $currentPlan->expires_at ?? $responseUser->plan_expire_date ?? null;
        $planStatus = $currentPlan->status ?? $responseUser->plan_status ?? null;
        $metadata = $this->normalizeMetadata(
            $currentPlan->metadata ?? ($responseUser->plan_metadata ?? $responseUser->plan ?? null)
        );
        $isInactiveStatus = in_array(
            strtolower(trim((string) $planStatus)),
            ['inactive', 'canceled', 'cancelled', 'expired'],
            true
        );
        if ($isInactiveStatus) {
            $appPlanId = null;
            $metadata = [];
        }

        $this->applyPlanSyncProfile($updates, $appPlanId, $expiresAt, $metadata);

        if (empty($updates)) {
            return $user;
        }

        $user->forceFill($updates);
        if ($user->isDirty()) {
            $user->save();
        }

        return $user->refresh();
    }

    private function applyPlanSyncProfile(array &$updates, mixed $appPlanId, mixed $expiresAt, mixed $metadata): void
    {
        $profile = $this->resolvePlanSyncProfile();

        if (!empty($appPlanId)) {
            foreach ($profile['id_columns'] as $column) {
                if ($this->hasUserColumn($column)) {
                    $updates[$column] = (int) $appPlanId;
                }
            }
        }

        if ($profile['sync_expiry'] && !empty($expiresAt)) {
            try {
                $expiresDate = Carbon::parse($expiresAt);
                if ($this->hasUserColumn('plan_expire_date')) {
                    $updates['plan_expire_date'] = $expiresDate->toDateString();
                }
                if ($this->hasUserColumn('plan_expire')) {
                    $updates['plan_expire'] = (string) $expiresDate->valueOf();
                }
            } catch (\Throwable) {
                // Ignore invalid date payloads and keep auth flow healthy.
            }
        }

        if ($profile['sync_metadata_json'] && $metadata !== null && $metadata !== '') {
            $encoded = json_encode($metadata);
            if ($this->hasUserColumn('plan_metadata')) {
                $updates['plan_metadata'] = $encoded;
            }
            if ($this->hasUserColumn('plan')) {
                $updates['plan'] = $encoded;
            }
        }

        if (!is_array($metadata)) {
            return;
        }

        foreach ($profile['metadata_map'] as $source => $map) {
            if (!array_key_exists($source, $metadata) || !$this->hasUserColumn($map['column'])) {
                continue;
            }

            $value = $this->castMappedValue($metadata[$source], $map['cast']);
            if ($value !== null) {
                $updates[$map['column']] = $value;
            }
        }
    }

    private function resolvePlanSyncProfile(): array
    {
        $appPrefix = strtolower($this->resolveAppPrefix());

        $default = [
            'id_columns' => ['active_plan', 'requested_plan'],
            'sync_expiry' => true,
            'sync_metadata_json' => true,
            'metadata_map' => [
                'modules' => ['column' => 'active_module', 'cast' => 'csv'],
                'number_of_workspace' => ['column' => 'total_workspace', 'cast' => 'string'],
                'number_of_user' => ['column' => 'total_user', 'cast' => 'int'],
            ],
        ];

        $profiles = [
            'ineventz' => array_merge($default, [
                'id_columns' => ['active_plan', 'requested_plan', 'plan_id'],
            ]),
        ];

        return $profiles[$appPrefix] ?? $default;
    }

    private function resolveAppPrefix(): string
    {
        $explicit = trim((string) env('BITES_APP_PREFIX', ''));
        if ($explicit !== '') {
            return strtolower($explicit);
        }

        $defaults = (array) config('bites.app_prefix_defaults', []);
        $normalizedDefaults = [];
        foreach ($defaults as $key => $value) {
            $normalizedDefaults[$this->normalizeKey((string) $key)] = trim((string) $value);
        }

        $candidates = [
            (string) config('app.name', ''),
            basename((string) base_path()),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeKey((string) $candidate);
            if ($normalized !== '' && !empty($normalizedDefaults[$normalized])) {
                return strtolower((string) $normalizedDefaults[$normalized]);
            }
        }

        $fallback = $this->normalizeKey((string) (config('app.name', '') ?: basename((string) base_path())));

        return $fallback !== '' ? strtolower($fallback) : '';
    }

    private function normalizeKey(string $value): string
    {
        $value = Str::lower(trim($value));
        if ($value === '') {
            return '';
        }

        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }

    private function castMappedValue(mixed $value, string $cast): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($cast) {
            'int' => (int) $value,
            'string' => (string) $value,
            'csv' => is_array($value)
                ? implode(',', array_values(array_filter($value, fn($item) => $item !== null && $item !== '')))
                : (string) $value,
            default => $value,
        };
    }

    private function normalizeMetadata(mixed $metadata): mixed
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        if (is_object($metadata)) {
            return (array) $metadata;
        }

        return $metadata;
    }

    private function hasUserColumn(string $column): bool
    {
        if (array_key_exists($column, self::$userColumnCache)) {
            return self::$userColumnCache[$column];
        }

        try {
            $table = (new User())->getTable();
            self::$userColumnCache[$column] = Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            self::$userColumnCache[$column] = false;
        }

        return self::$userColumnCache[$column];
    }

    private function middlewareBaseUrl(): string
    {
        $server = trim((string) env("MIDDLEWARE_SERVER", "middleware.bites.com"));
        if ($server === "") {
            $server = "middleware.bites.com";
        }

        if (!preg_match("#^https?://#i", $server)) {
            $server = "https://" . $server;
        }

        return rtrim($server, "/");
    }
}
