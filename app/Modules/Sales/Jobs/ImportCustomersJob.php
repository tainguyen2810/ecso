<?php

namespace App\Modules\Sales\Jobs;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sales\Models\CustomerImport;
use App\Modules\Sales\Services\CustomerImporter;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Checks (mode check) or imports (mode import) a large customer file. Each step claims the import atomically, so a
 * job delivered twice does the work once. Importing checks every row again (the data may have changed since the
 * check) and writes nothing when one row is wrong; the rows are written in one transaction, so a failure part way
 * leaves no row written.
 */
class ImportCustomersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CHECK = 'check';

    public const IMPORT = 'import';

    public const DISK = 'local';

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $importId, public readonly string $mode)
    {
        $this->onQueue('default');
    }

    public function handle(CustomerImporter $importer, AuditLogger $audit): void
    {
        $import = CustomerImport::query()->withoutGlobalScopes()->find($this->importId);
        if ($import === null) {
            return;
        }
        // 30 000 rows and their checks are held in memory at once (about 120 MB measured).
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && $this->bytes($limit) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }

        TenantContext::run(Tenant::query()->findOrFail($import->tenant_id), function () use ($import, $importer, $audit) {
            [$from, $to] = $this->mode === self::IMPORT ? [CustomerImport::QUEUED, CustomerImport::IMPORTING] : [CustomerImport::PENDING_CHECK, CustomerImport::CHECKING];
            if (! $import->claim($from, $to)) {
                return;
            }
            if ($import->user) {
                Auth::setUser($import->user); // the customers it creates are "created by" whoever uploaded the file
            }

            $path = $import->path !== null ? Storage::disk(self::DISK)->path($import->path) : null;
            if ($path === null || ! is_file($path)) {
                $this->finish($import, CustomerImport::FAILED, ['message' => 'Không còn tệp để nhập, vui lòng tải lên lại.']);

                return;
            }

            $parsed = $importer->read($path, pathinfo($path, PATHINFO_EXTENSION), CustomerImporter::backgroundRows());
            if ($parsed['more']) {
                $parsed['errors'][] = ['row' => 0, 'message' => 'Tệp vượt quá '.number_format(CustomerImporter::backgroundRows(), 0, ',', '.').' dòng, hãy chia thành nhiều tệp.'];
            }
            if ($parsed['errors'] || $parsed['rows'] === []) {
                $errors = $parsed['errors'] ?: [['row' => 0, 'message' => 'Tệp không có dòng dữ liệu.']];
                $this->finish($import, CustomerImport::FAILED, ['total' => count($parsed['rows'])] + $this->errors($errors) + ['message' => 'Tệp không hợp lệ.']);

                return;
            }

            if ($this->mode === self::CHECK) {
                $result = $importer->import($parsed['rows'], dryRun: true);
                $this->finish($import, $result['errors'] ? CustomerImport::FAILED : CustomerImport::CHECKED, [
                    'total' => count($parsed['rows']), 'created_count' => $result['created'], 'updated_count' => $result['updated'],
                    'message' => $result['errors'] ? 'Có lỗi, chưa nhập dòng nào.' : null,
                ] + $this->errors($result['errors']), keepFile: ! $result['errors']);

                return;
            }

            $result = $importer->import($parsed['rows'], progress: function (int $done) use ($import): void {
                Cache::put($import->progressKey(), $done, 3600);
            });
            if ($result['errors']) {
                // Someone changed the customers since the check (a phone or code now taken): nothing was written.
                $this->finish($import, CustomerImport::FAILED, ['message' => 'Dữ liệu khách đã thay đổi từ lúc kiểm tra, chưa nhập dòng nào.'] + $this->errors($result['errors']));

                return;
            }
            $this->finish($import, CustomerImport::DONE, ['created_count' => $result['created'], 'updated_count' => $result['updated'], 'errors' => null, 'error_count' => 0]);
            $audit->record('customer.imported', null, ['file' => $import->file_name, 'created' => $result['created'], 'updated' => $result['updated'], 'background' => true]);
        });
    }

    /**
     * The worker gave up (timeout): the transaction was rolled back, so nothing was written. A second delivery of a
     * job still running (the queue re-sends a job after its retry time) fails as "too many attempts": that one is not
     * the import failing, so it is ignored; an import whose worker died is marked failed by CustomerImport::pruneStale.
     */
    public function failed(?\Throwable $e): void
    {
        if ($e instanceof MaxAttemptsExceededException) {
            return;
        }
        $import = CustomerImport::query()->withoutGlobalScopes()->find($this->importId);
        if ($import !== null && in_array($import->status, [CustomerImport::CHECKING, CustomerImport::IMPORTING], true)) {
            $this->finish($import, CustomerImport::FAILED, ['message' => 'Lỗi khi xử lý tệp, chưa nhập dòng nào. Vui lòng thử lại hoặc chia nhỏ tệp.']);
        }
    }

    private function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @param  list<array{row: int, message: string}>  $errors
     * @return array{errors: list<array{row: int, message: string}>|null, error_count: int}
     */
    private function errors(array $errors): array
    {
        return ['errors' => $errors ? array_slice($errors, 0, CustomerImport::KEEP_ERRORS) : null, 'error_count' => count($errors)];
    }

    /** @param  array<string, mixed>  $values */
    private function finish(CustomerImport $import, string $status, array $values, bool $keepFile = false): void
    {
        if (! $keepFile && $import->path !== null) {
            Storage::disk(self::DISK)->delete($import->path); // the file holds phones and addresses: not kept longer than needed
            $values['path'] = null;
        }
        $import->forceFill($values + ['status' => $status, 'finished_at' => now()])->save();
        Cache::forget($import->progressKey());
    }
}
