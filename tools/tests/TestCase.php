<?php
declare(strict_types=1);

namespace Tools\Tests;

/**
 * A deliberately tiny test harness.
 *
 * The host may not have Composer, and adding a vendor directory for an
 * assertion library is not worth the deployment friction. Run with:
 *
 *     php tools/tests/run.php
 */
abstract class TestCase
{
    private int $passed = 0;
    /** @var list<string> */
    private array $failures = [];
    private string $current = '';

    abstract public function run(): void;

    final public function execute(): array
    {
        foreach (get_class_methods($this) as $method) {
            if (str_starts_with($method, 'test')) {
                $this->current = $method;
                try {
                    $this->$method();
                } catch (\Throwable $e) {
                    $this->failures[] = sprintf(
                        '%s::%s threw %s: %s',
                        static::class, $method, get_class($e), $e->getMessage()
                    );
                }
            }
        }

        return [$this->passed, $this->failures];
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            $this->passed++;
            return;
        }
        $this->fail($message, $this->render($expected), $this->render($actual));
    }

    protected function assertTrue(bool $actual, string $message = ''): void
    {
        $this->assertSame(true, $actual, $message);
    }

    protected function assertFalse(bool $actual, string $message = ''): void
    {
        $this->assertSame(false, $actual, $message);
    }

    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (in_array($needle, $haystack, true)) {
            $this->passed++;
            return;
        }
        $this->fail($message, $this->render($needle) . ' present', $this->render($haystack));
    }

    protected function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            $this->passed++;
            return;
        }
        $this->fail($message, $this->render($needle) . ' absent', $this->render($haystack));
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            $this->passed++;
            return;
        }
        $this->fail($message, "string containing {$needle}", $this->render($haystack));
    }

    protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            $this->passed++;
            return;
        }
        $this->fail($message, "string without {$needle}", $this->render($haystack));
    }

    private function fail(string $message, string $expected, string $actual): void
    {
        $this->failures[] = sprintf(
            "%s::%s\n      %s\n      expected: %s\n      actual:   %s",
            static::class,
            $this->current,
            $message !== '' ? $message : '(no description)',
            $expected,
            $actual
        );
    }

    private function render(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[?]';
        }
        return var_export($value, true);
    }
}
