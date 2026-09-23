<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use App\Core\Paths;

/**
 * Applies db/migrations/*.sql in order, once each.
 *
 * Used by tools/migrate.php and by Admin → Settings, because cPanel shared
 * hosting often has no Terminal, and an update that ships a migration must
 * not strand the site owner.
 */
final class Migrator
{
    /** @return list<string> migration files, in order */
    public static function files(): array
    {
        $files = glob(Paths::root() . '/db/migrations/*.sql') ?: [];
        sort($files);

        return $files;
    }

    public static function ensureTable(): void
    {
        Database::connect()->exec(
            'CREATE TABLE IF NOT EXISTS `migrations` (
                `version` VARCHAR(20) NOT NULL,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<array{version:string, file:string, applied:bool}> */
    public static function status(): array
    {
        self::ensureTable();
        $applied = Database::column('SELECT version FROM migrations');

        return array_map(static fn(string $file) => [
            'version' => self::version($file),
            'file'    => basename($file),
            'applied' => in_array(self::version($file), $applied, true),
        ], self::files());
    }

    /** @return list<string> file names not yet applied */
    public static function pending(): array
    {
        return array_values(array_map(
            static fn(array $m) => $m['file'],
            array_filter(self::status(), static fn(array $m) => !$m['applied'])
        ));
    }

    /**
     * Apply everything pending, stopping at the first failure.
     *
     * @return array{applied: list<string>, failed: ?string, error: ?string}
     */
    public static function applyPending(?callable $progress = null): array
    {
        $pdo = Database::connect();
        $done = [];

        foreach (self::status() as $migration) {
            if ($migration['applied']) {
                continue;
            }
            $progress !== null && $progress($migration['file']);

            try {
                $sql = file_get_contents(Paths::root() . '/db/migrations/' . $migration['file']);
                if ($sql === false) {
                    throw new \RuntimeException('could not read the file');
                }
                foreach (SqlScript::split($sql) as $statement) {
                    $pdo->exec($statement);
                }
                Database::insert('migrations', ['version' => $migration['version']]);
                $done[] = $migration['file'];
            } catch (\Throwable $e) {
                return ['applied' => $done, 'failed' => $migration['file'], 'error' => $e->getMessage()];
            }
        }

        return ['applied' => $done, 'failed' => null, 'error' => null];
    }

    private static function version(string $file): string
    {
        return substr(basename($file), 0, 3);
    }
}
