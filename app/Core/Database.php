<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper.
 *
 * Deliberately not an ORM. Every query in this codebase is visible SQL with
 * bound parameters, which keeps it readable and keeps it honest about what
 * hits MariaDB on a shared host.
 *
 * Target is MariaDB 10.3 (what the cPanel host runs). That means:
 *   - no native JSON column type (JSON columns are LONGTEXT)
 *   - no INSERT ... RETURNING   (10.5+)
 *   - no ALTER TABLE ... RENAME COLUMN (10.5+)
 *   - no JSON_TABLE             (10.6+)
 * Local development runs a newer MariaDB, so these have to be avoided by
 * discipline rather than by the engine complaining. See docs/RUNBOOK.md.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $queryCount = 0;

    public static function connect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Config::string('db.host', '127.0.0.1'),
            Config::int('db.port', 3306),
            Config::string('db.name'),
            Config::string('db.charset', 'utf8mb4')
        );

        try {
            self::$pdo = new PDO($dsn, Config::string('db.user'), Config::string('db.pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements, not client-side interpolation.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                // The session timezone is pinned to PHP's, so NOW() and PHP's
                // time() agree. Without this, every comparison between a
                // stored timestamp and the current time is wrong by the
                // offset — the worker reads as silent seconds after it ran,
                // and scheduled publishing fires at the wrong hour.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci,"
                    . " time_zone = '" . self::utcOffset() . "',"
                    . " sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
            ]);
        } catch (\PDOException $e) {
            // Never leak credentials into an error page.
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /**
     * PHP's current UTC offset as MySQL wants it ("+03:30").
     *
     * Named zones are not usable here: MySQL only knows them when the
     * timezone tables have been loaded, which they are not on most shared
     * hosting. A numeric offset always works.
     */
    private static function utcOffset(): string
    {
        $timezone = new \DateTimeZone(Config::string('site.timezone', 'UTC'));
        $offset = $timezone->getOffset(new \DateTimeImmutable('now', $timezone));

        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);

        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }

    /** Inject a connection. Used by tests and by the CLI tools. */
    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        self::$queryCount++;
        $statement = self::connect()->prepare($sql);
        $statement->execute(self::flatten($params));

        return $statement;
    }

    /** @return array<string,mixed>|null */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** First column of the first row, or $default when there is no row. */
    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? $default : $value;
    }

    /** @return list<mixed> First column of every row. */
    public static function column(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Map the first column to the second. */
    public static function pairs(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn($c) => "`{$c}`", $columns)),
            implode(', ', array_map(static fn($c) => ':' . $c, $columns))
        );

        self::run($sql, $data);

        return (int) self::connect()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $assignments = [];
        $params = [];
        foreach ($data as $column => $value) {
            $assignments[] = "`{$column}` = :set_{$column}";
            $params["set_{$column}"] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $assignments), $where);

        return self::run($sql, [...$params, ...$whereParams])->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::run(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $params)->rowCount();
    }

    /**
     * Build a safe `IN (...)` clause.
     *
     * @return array{0:string,1:array<string,mixed>} placeholder list and params
     */
    public static function inClause(array $values, string $prefix = 'in'): array
    {
        if ($values === []) {
            // An empty IN () is a syntax error; this is the equivalent no-op.
            return ['NULL', []];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $key = "{$prefix}{$i}";
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }

        return [implode(', ', $placeholders), $params];
    }

    public static function transaction(callable $work): mixed
    {
        $pdo = self::connect();

        // Nested calls join the outer transaction rather than failing.
        if ($pdo->inTransaction()) {
            return $work($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    /**
     * PDO cannot bind booleans or arrays in this configuration, and binding a
     * PHP null to a NOT NULL column produces a confusing error, so normalise
     * here in one place.
     */
    private static function flatten(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $value ? 1 : 0;
            } elseif ($value instanceof \DateTimeInterface) {
                $params[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_array($value)) {
                throw new \InvalidArgumentException("Cannot bind an array to :{$key}. Use Database::inClause().");
            }
        }

        return $params;
    }
}
