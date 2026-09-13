<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class ProfileService
{
    public function cacheProfile(int $userId, array $payload): void
    {
        Cache::put("user:{$userId}", $payload, 3600);
    }

    public function storeAvatar($user, $file): void
    {
        Storage::disk('s3')->put("avatars/{$user->id}.jpg", $file);
    }

    public function writeSessionBlob($user, string $json): void
    {
        Redis::set('profile:' . $user->id, $json);
    }

    public function cacheWithConcat($user): void
    {
        Cache::forever('settings/' . $user->getKey() . '/v2', []);
    }

    /** Not personal: keyed on a report, not a person. */
    public function cacheReport(int $reportId): void
    {
        Cache::put("report:{$reportId}", []);
    }

    /** Not personal: wholly literal key. */
    public function cacheGlobalConfig(): void
    {
        Cache::forever('app:config', []);
    }

    /** Reads are not writes. */
    public function readProfile(int $userId)
    {
        return Cache::get("user:{$userId}");
    }
}
