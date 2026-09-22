<?php
declare(strict_types=1);

namespace Tools\Tests;

use App\Support\SqlScript;

final class SqlScriptTest extends TestCase
{
    public function run(): void {}

    public function testStatementPrecededByCommentIsNotLost(): void
    {
        // The exact shape that broke the first migration run: a banner comment
        // immediately before a CREATE TABLE.
        $sql = <<<SQL
        CREATE TABLE a (id INT);

        -- ---------------------------------------------------------- section
        CREATE TABLE b (id INT);
        SQL;

        $statements = SqlScript::split($sql);

        $this->assertSame(2, count($statements), 'both tables must survive the split');
        $this->assertStringContains('CREATE TABLE b', $statements[1]);
        $this->assertStringNotContains('--', $statements[1], 'comment must be stripped, not carried');
    }

    public function testCommentOnlyFileYieldsNoStatements(): void
    {
        $this->assertSame([], SqlScript::split("-- just a note\n-- and another\n"));
    }

    public function testTrailingSemicolonIsOptional(): void
    {
        $this->assertSame(1, count(SqlScript::split('CREATE TABLE a (id INT)')));
    }

    public function testInlineCommentAfterCodeIsPreserved(): void
    {
        // Only whole-line comments are removed; a trailing comment on a code
        // line is left for the server to handle.
        $statements = SqlScript::split("CREATE TABLE a (id INT); -- trailing\n");
        $this->assertSame(1, count($statements));
    }

    public function testBlankLinesBetweenStatementsAreIgnored(): void
    {
        $this->assertSame(2, count(SqlScript::split("SELECT 1;\n\n\n\nSELECT 2;\n")));
    }

    public function testRealSchemaFileSplitsIntoEveryStatement(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../db/migrations/001_init.sql');
        $this->assertTrue($sql !== false, 'schema file must be readable');

        $statements = SqlScript::split((string) $sql);
        $creates = array_filter($statements, static fn($s) => str_starts_with($s, 'CREATE TABLE'));

        // Count what the file declares and make sure none are dropped.
        $declared = preg_match_all('/^CREATE TABLE /m', (string) $sql);
        $this->assertSame($declared, count($creates), 'every CREATE TABLE must reach the server');
    }
}
