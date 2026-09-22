<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Splits a .sql migration file into executable statements.
 *
 * The schema contains no stored routines, so semicolon splitting is enough
 * and a real SQL parser would be overkill.
 */
final class SqlScript
{
    /**
     * Comment stripping happens before splitting, never after.
     *
     * Splitting first and then discarding chunks that start with `--` looks
     * equivalent but is not: a chunk that merely *begins* with a comment still
     * has a real statement underneath it. Dropping the whole chunk silently
     * skips table creation and surfaces as a baffling foreign-key error
     * several tables later.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $lines[] = $line;
        }

        $statements = [];
        foreach (preg_split('/;\s*$/m', implode("\n", $lines)) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
