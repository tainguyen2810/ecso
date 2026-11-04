<?php

namespace App\Modules\Sales\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Shared\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * A large customer file checked and imported in the background. Same rules as an import done at once: every row is
 * checked first and nothing is written when one row is wrong; the rows are written in one transaction.
 *
 * @property list<array{row: int, message: string}>|null $errors
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class CustomerImport extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const PENDING_CHECK = 'pending_check';

    public const CHECKING = 'checking';

    public const CHECKED = 'checked';

    public const QUEUED = 'queued';

    public const IMPORTING = 'importing';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    /** States in which the file is still being worked on. */
    public const RUNNING = [self::PENDING_CHECK, self::CHECKING, self::QUEUED, self::IMPORTING];

    /** At most this many errors are kept to show (the count is kept in full). */
    public const KEEP_ERRORS = 500;

    protected $hidden = ['id', 'tenant_id', 'user_id', 'path'];

    protected function casts(): array
    {
        return ['errors' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressKey(): string
    {
        return "customer-import:{$this->ulid}:processed";
    }

    /** Rows written so far while importing (kept outside the import transaction, so it can be read meanwhile). */
    public function processed(): int
    {
        return match ($this->status) {
            self::DONE => $this->total,
            self::IMPORTING => (int) Cache::get($this->progressKey(), 0),
            default => 0,
        };
    }

    /**
     * Atomically moves the import from one state to the next; false when another worker (or a repeated delivery of
     * the same job) already did.
     */
    public function claim(string $from, string $to): bool
    {
        $moved = static::query()->whereKey($this->id)->where('status', $from)
            ->update(['status' => $to, 'started_at' => $this->freshTimestamp(), 'updated_at' => $this->freshTimestamp()]);
        if ($moved) {
            $this->refresh();
        }

        return $moved === 1;
    }

    /**
     * Drops the file of a check never imported after a day, and marks failed an import left running for two hours
     * (its worker died; the rows were written in one transaction, so none is in). Runs for every business.
     */
    public static function pruneStale(): int
    {
        $count = 0;
        $stale = static::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('status', self::CHECKED)->where('updated_at', '<', now()->subDay()))
            ->orWhere(fn ($q) => $q->whereIn('status', self::RUNNING)->where('updated_at', '<', now()->subHours(2)))
            ->get();
        foreach ($stale as $import) {
            if ($import->path !== null) {
                Storage::disk('local')->delete($import->path);
            }
            $expired = $import->status === self::CHECKED;
            $import->forceFill([
                'status' => $expired ? self::EXPIRED : self::FAILED, 'path' => null, 'finished_at' => now(),
                'message' => $expired ? 'Tệp đã kiểm tra nhưng không được nhập trong 24 giờ, đã xóa tệp.' : 'Nhập bị gián đoạn, chưa nhập dòng nào. Vui lòng thử lại.',
            ])->save();
            $count++;
        }

        return $count;
    }
}
