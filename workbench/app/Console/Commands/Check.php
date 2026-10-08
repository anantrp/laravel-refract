<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Throwable;
use Workbench\App\Langfuse\TreeReader;
use Workbench\App\Scenarios;

use function Orchestra\Testbench\package_path;

class Check extends Command
{
    protected $signature = 'refract:check {row : Scenario name, for example R1}';

    protected $description = 'Run a row scenario, read its tree from Langfuse and compare it to workbench/expected/{row}.txt';

    public function handle(TreeReader $reader, Scenarios $scenarios): int
    {
        $row = $this->argument('row');
        $row = is_string($row) ? strtoupper($row) : '';

        try {
            $reader->ensureKeys();

            $expectedFile = package_path('workbench', 'expected', "{$row}.txt");

            if (! is_file($expectedFile)) {
                return $this->failWith("no expected file workbench/expected/{$row}.txt");
            }

            // A separate process, so Refract flushes when that command ends.
            $scenario = Process::path(package_path())
                ->env($scenarios->environment($row))
                ->timeout(120)
                ->run([PHP_BINARY, 'vendor/bin/testbench', 'refract:scenario', $row]);

            if ($scenario->failed()) {
                return $this->failWith("scenario {$row} failed:\n".trim($scenario->output()."\n".$scenario->errorOutput()));
            }

            $lines = preg_split('/\R/', trim($scenario->output())) ?: [];
            $traceId = trim((string) end($lines));

            $this->line("trace {$traceId}");

            $expected = trim((string) file_get_contents($expectedFile));

            // Every line but the environment header is one span.
            $actual = $reader->tree($traceId, withTimes: false, expected: substr_count($expected, "\n"));
        } catch (Throwable $e) {
            return $this->failWith($e->getMessage());
        }

        if ($actual === $expected) {
            $this->info('PASS');

            return self::SUCCESS;
        }

        $differ = new Differ(new UnifiedDiffOutputBuilder("--- expected\n+++ actual\n"));

        $this->line($differ->diff($expected."\n", $actual."\n"));

        return $this->failWith('tree does not match');
    }

    protected function failWith(string $reason): int
    {
        $this->error("FAIL: {$reason}");

        return self::FAILURE;
    }
}
