<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncError;

/** Opens, repeats and closes sync errors. Runs in the account's tenant context. */
class SyncErrorLog
{
    public function record(MarketplaceAccount $account, string $resource, string $externalId, string $category, string $message): SyncError
    {
        $open = $this->open($account, $resource, $externalId);
        $message = mb_substr($message, 0, 500);

        if ($open) {
            $open->forceFill(['category' => $category, 'message' => $message, 'attempts' => $open->attempts + 1, 'last_seen_at' => now()])->save();

            return $open;
        }

        return SyncError::query()->create([
            'marketplace_account_id' => $account->id, 'resource' => $resource, 'external_id' => $externalId,
            'category' => $category, 'message' => $message, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function fromException(MarketplaceAccount $account, string $resource, string $externalId, ConnectorException $e): SyncError
    {
        $category = match (true) {
            $e->authExpired => SyncError::AUTH,
            $e->notFound => SyncError::NOT_FOUND,
            $e->temporary => SyncError::API,
            default => SyncError::DATA,
        };

        return $this->record($account, $resource, $externalId, $category, $e->getMessage());
    }

    /** Closes the open error of this id, if any (a later sync of it went through, or a user dismissed it). */
    public function resolve(MarketplaceAccount $account, string $resource, string $externalId, string $resolution = SyncError::SYNCED, ?int $userId = null): void
    {
        $this->open($account, $resource, $externalId)?->forceFill(['resolved_at' => now(), 'resolution' => $resolution, 'resolved_by' => $userId])->save();
    }

    private function open(MarketplaceAccount $account, string $resource, string $externalId): ?SyncError
    {
        return SyncError::query()->where('marketplace_account_id', $account->id)->where('resource', $resource)
            ->where('external_id', $externalId)->whereNull('resolved_at')->first();
    }
}
