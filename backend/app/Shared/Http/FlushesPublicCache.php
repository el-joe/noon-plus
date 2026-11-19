<?php

namespace App\Shared\Http;

use Illuminate\Database\Eloquent\Model;

/**
 * Forgets the {@see PublicCache} groups a model feeds whenever a row is
 * saved or deleted. A model lists its groups in `publicCacheGroups()`
 * (per-country groups) or `centralPublicCacheGroups()` (central groups),
 * and may name counter columns (`views`) whose changes alone keep the cache.
 */
trait FlushesPublicCache
{
    protected static function bootFlushesPublicCache(): void
    {
        $flush = function (Model $model): void {
            $ignored = method_exists($model, 'publicCacheIgnoredColumns') ? $model->publicCacheIgnoredColumns() : [];

            if ($model->exists && ! $model->wasRecentlyCreated && $ignored !== [] && array_diff(array_keys($model->getChanges()), [...$ignored, $model->getUpdatedAtColumn()]) === []) {
                return;
            }

            foreach (method_exists($model, 'publicCacheGroups') ? $model->publicCacheGroups() : [] as $group) {
                PublicCache::flushCountry($group);
            }

            foreach (method_exists($model, 'centralPublicCacheGroups') ? $model->centralPublicCacheGroups() : [] as $group) {
                PublicCache::flush($group);
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }
}
