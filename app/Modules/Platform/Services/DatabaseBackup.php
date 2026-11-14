<?php

namespace App\Modules\Platform\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use Throwable;

/**
 * Daily database backup written by PHP itself (A6). No mysqldump, exec or proc_open: aaPanel often disables
 * those for PHP and the database may live in a Docker container the www user cannot reach with a client.
 * The dump is a plain SQL file (gzip) in storage/app/backups, which stays inside the site's open_basedir.
 *
 * Restore: gunzip < ecso-<db>-<time>.sql.gz | mysql -h <host> -P <port> -u <user> -p <db>
 */
class DatabaseBackup
{
    /** Rows per INSERT statement. */
    private const BATCH = 200;

    private const STATUS_FILE = 'last-backup.json';

    public function directory(): string
    {
        return rtrim((string) (config('ecso.backup.path') ?: storage_path('app/backups')), '/');
    }

    /**
     * @return array{file: string, bytes: int, tables: int, rows: int, seconds: float, deleted: int}
     */
    public function run(): array
    {
        $started = microtime(true);
        $dir = $this->directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->fail("Không tạo được thư mục sao lưu {$dir}.");
        }

        $connection = $this->connection();
        $database = (string) $connection->getDatabaseName();
        $file = $dir.'/ecso-'.preg_replace('/[^A-Za-z0-9_\-]/', '_', $database).'-'.gmdate('Ymd\THis\Z').'.sql.gz';
        $part = $file.'.part';

        try {
            [$tables, $rows] = $this->dump($connection, $database, $part);
            if (! @rename($part, $file)) {
                throw new RuntimeException("Không đổi tên được file {$part}.");
            }
            @chmod($file, 0600);
            file_put_contents($file.'.sha256', hash_file('sha256', $file).'  '.basename($file)."\n");
        } catch (Throwable $e) {
            @unlink($part);

            $this->fail($e->getMessage());
        } finally {
            if ($connection->getName() === $this->dedicatedName()) {
                DB::purge($this->dedicatedName());
            }
        }

        $result = [
            'file' => $file,
            'bytes' => (int) filesize($file),
            'tables' => $tables,
            'rows' => $rows,
            'seconds' => round(microtime(true) - $started, 1),
            'deleted' => $this->prune(),
        ];
        $this->writeStatus(['ok' => true, 'at' => now()->toIso8601String(), 'file' => basename($file), 'bytes' => $result['bytes']]);

        return $result;
    }

    /** @return array<string, mixed>|null {ok, at, file?, bytes?, error?, last_ok_at?} */
    public function status(): ?array
    {
        $path = $this->directory().'/'.self::STATUS_FILE;
        $status = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($status) && isset($status['ok'], $status['at']) ? $status : null;
    }

    /** A sentence for `ecso:monitor`, or null when backups are healthy (or have not run yet). */
    public function problem(): ?string
    {
        if (! config('ecso.backup.enabled')) {
            return null;
        }
        $status = $this->status();
        if ($status === null) {
            return null;
        }
        if (! $status['ok']) {
            return 'Sao lưu database lỗi: '.(string) ($status['error'] ?? 'không rõ');
        }
        if (strtotime((string) $status['at']) < now()->subHours(26)->getTimestamp()) {
            return 'Quá 26 giờ chưa có bản sao lưu database mới';
        }

        return null;
    }

    /** Deletes backups older than the keep window, always leaving the newest few. */
    public function prune(): int
    {
        $files = glob($this->directory().'/ecso-*.sql.gz') ?: [];
        rsort($files); // names carry the UTC time, so newest first
        $cutoff = now()->subDays(max(1, (int) config('ecso.backup.keep_days')))->getTimestamp();
        $deleted = 0;
        foreach (array_slice($files, 3) as $old) {
            if (filemtime($old) < $cutoff) {
                @unlink($old);
                @unlink($old.'.sha256');
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return array{0: int, 1: int} tables, rows */
    private function dump(Connection $connection, string $database, string $path): array
    {
        $pdo = $connection->getPdo();
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Không ghi được file {$path}.");
        }

        $snapshot = $connection->transactionLevel() === 0;
        if ($snapshot) {
            // One consistent InnoDB snapshot for every table, without locking writers.
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }

        try {
            $write = function (string $sql) use ($gz): void {
                if (gzwrite($gz, $sql) === false) {
                    throw new RuntimeException('Ghi file sao lưu thất bại (ổ đĩa đầy?).');
                }
            };
            $write("-- EzSell database backup\n-- Database: {$database}\n-- Created: ".now()->toIso8601String()."\n\n"
                ."SET NAMES utf8mb4;\nSET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n"
                ."SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;\nSET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = $views = [];
            foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
                $type === 'VIEW' ? $views[] = $name : $tables[] = $name;
            }

            $rows = 0;
            foreach ($tables as $table) {
                $q = $this->quoteName($table);
                $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetchAll(PDO::FETCH_NUM)[0][1];
                $write("DROP TABLE IF EXISTS {$q};\n{$create};\n\n");
                $rows += $this->dumpRows($pdo, $database, $table, $write);
            }
            foreach ($views as $view) {
                $q = $this->quoteName($view);
                $create = (string) $pdo->query("SHOW CREATE VIEW {$q}")->fetchAll(PDO::FETCH_NUM)[0][1];
                $create = (string) preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $create);
                $write("DROP VIEW IF EXISTS {$q};\n{$create};\n\n");
            }

            $write("SET SQL_MODE=@OLD_SQL_MODE;\nSET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;\nSET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n-- Done\n");
        } finally {
            if ($snapshot) {
                $pdo->exec('COMMIT');
            }
            gzclose($gz);
        }

        return [count($tables), $rows];
    }

    /** @param  callable(string): void  $write */
    private function dumpRows(PDO $pdo, string $database, string $table, callable $write): int
    {
        // Generated columns cannot be inserted; they are rebuilt from the others.
        $stmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION");
        $stmt->execute([$database, $table]);
        $types = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($types === []) {
            return 0;
        }
        $columns = array_map('strval', array_keys($types));
        // Binary columns go out as hex, so bytes that are not valid UTF-8 survive the restore.
        $binary = array_map(fn ($type) => (bool) preg_match('/binary|blob/i', (string) $type), array_values($types));

        $list = implode(', ', array_map($this->quoteName(...), $columns));
        $q = $this->quoteName($table);
        $result = $pdo->query("SELECT {$list} FROM {$q}");
        $count = 0;
        $batch = [];
        while (($row = $result->fetch(PDO::FETCH_NUM)) !== false) {
            $values = [];
            foreach ($row as $i => $v) {
                $values[] = match (true) {
                    $v === null => 'NULL',
                    $binary[$i] => "X'".bin2hex((string) $v)."'",
                    default => $pdo->quote((string) $v),
                };
            }
            $batch[] = '('.implode(',', $values).')';
            $count++;
            if (count($batch) >= self::BATCH) {
                $write("INSERT INTO {$q} ({$list}) VALUES\n".implode(",\n", $batch).";\n");
                $batch = [];
            }
        }
        $result->closeCursor();
        if ($batch !== []) {
            $write("INSERT INTO {$q} ({$list}) VALUES\n".implode(",\n", $batch).";\n");
        }
        if ($count > 0) {
            $write("\n");
        }

        return $count;
    }

    /**
     * A connection of its own (unless configured otherwise), so the snapshot transaction and the unbuffered reads
     * never touch the connection the rest of the app is using.
     */
    private function connection(): Connection
    {
        $name = config('ecso.backup.connection');
        if (is_string($name) && $name !== '') {
            return DB::connection($name);
        }

        $base = (string) config('database.default');
        $config = (array) config("database.connections.{$base}");
        $buffered = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
        $config['options'] = ($config['options'] ?? []) + [$buffered => false];
        config(["database.connections.{$this->dedicatedName()}" => $config]);

        return DB::connection($this->dedicatedName());
    }

    private function dedicatedName(): string
    {
        return 'ecso_backup';
    }

    private function quoteName(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function fail(string $error): never
    {
        $previous = $this->status();
        $this->writeStatus([
            'ok' => false,
            'at' => now()->toIso8601String(),
            'error' => $error,
            'last_ok_at' => ($previous['ok'] ?? false) ? $previous['at'] : ($previous['last_ok_at'] ?? null),
        ]);

        throw new RuntimeException($error);
    }

    /** @param  array<string, mixed>  $status */
    private function writeStatus(array $status): void
    {
        @file_put_contents($this->directory().'/'.self::STATUS_FILE, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
