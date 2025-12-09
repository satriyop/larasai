<?php

declare(strict_types=1);

namespace Larasai\UserStories\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Larasai\UserStories\Concerns\MatchesTestsToAcceptanceCriteria;

use function Laravel\Prompts\confirm;

class GenerateTestTodoFromStories extends Command
{
    use MatchesTestsToAcceptanceCriteria;

    protected $signature = 'test:generate-todo
        {module : Module name (e.g., attendance, project-management)}
        {--stories= : Path to user stories markdown file}
        {--tests= : Path to tests directory}
        {--output= : Output directory for TODO files}
        {--run-tests : Actually run tests to get pass/fail status}
        {--dry-run : Show what would be generated without writing files}
        {--force : Overwrite existing files}';

    protected $description = 'Generate test TODO documentation from user stories, mapping tests to acceptance criteria';

    private array $testResults = [];

    private array $parsedStories = [];

    public function handle(): int
    {
        $module = $this->argument('module');

        $this->info('🔍 Validating User Stories format...');
        $lintResult = LintUserStories::validate($module);

        if (! $lintResult['valid']) {
            $this->error('❌ User Stories file has validation errors:');
            $this->newLine();
            foreach ($lintResult['errors'] as $error) {
                $this->line("  <fg=red>✕</> Line {$error['line']}: [{$error['code']}] {$error['message']}");
            }
            $this->newLine();
            $this->line('Run <fg=yellow>php artisan stories:lint '.$module.'</> for details.');

            if (! confirm('Continue anyway? (Not recommended)', false)) {
                return self::FAILURE;
            }
        } else {
            $this->info('✅ User Stories format is valid');
        }
        $this->newLine();

        $storiesPath = $this->option('stories') ?? $this->guessStoriesPathWithPrefix($module);
        $testsPath = $this->option('tests') ?? $this->guessTestsPath($module);
        $outputPath = $this->option('output') ?? $this->guessOutputPath($module);

        $this->info("📋 Generating Test TODO for module: {$module}");
        $this->newLine();

        if (! File::exists($storiesPath)) {
            $this->error("User stories file not found: {$storiesPath}");

            return self::FAILURE;
        }

        if (! File::isDirectory($testsPath)) {
            $this->error("Tests directory not found: {$testsPath}");

            return self::FAILURE;
        }

        $this->info('1️⃣  Parsing user stories...');
        $this->parsedStories = $this->parseUserStories($storiesPath);
        $this->line("   Found {$this->countEpics()} Epics, {$this->countUserStories()} User Stories, {$this->countACs()} Acceptance Criteria");

        $this->info('2️⃣  Discovering tests...');
        $testFiles = $this->discoverTestFiles($testsPath);
        $this->line('   Found '.count($testFiles).' test files');

        if ($this->option('run-tests')) {
            $this->info('3️⃣  Running tests...');
            $this->testResults = $this->runTests($testsPath);
            $passed = collect($this->testResults)->where('status', 'passed')->count();
            $failed = collect($this->testResults)->where('status', 'failed')->count();
            $this->line("   Results: {$passed} passed, {$failed} failed");
        } else {
            $this->info('3️⃣  Extracting test names (use --run-tests to get pass/fail status)...');
            $this->testResults = $this->extractTestNames($testFiles);
            $this->line('   Found '.count($this->testResults).' tests');
        }

        $this->info('4️⃣  Generating TODO files...');

        if ($this->option('dry-run')) {
            $this->warn('   [DRY RUN] Would generate:');
            foreach ($this->parsedStories['epics'] as $epicNum => $epic) {
                $this->line("   - {$outputPath}/{$module}_TEST_TO_DO_EPIC_{$epicNum}.md");
            }
            if (! empty($this->parsedStories['nfr'])) {
                $this->line("   - {$outputPath}/{$module}_TEST_TO_DO_NFR.md");
            }

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($outputPath);
        $filesGenerated = $this->generateTodoFiles($module, $outputPath);

        $this->newLine();
        $this->info("✅ Generated {$filesGenerated} TODO files in {$outputPath}");

        return self::SUCCESS;
    }

    private function guessTestsPath(string $module): string
    {
        $folderName = Str::studly(Str::replace('-', ' ', $module));

        return base_path("tests/Feature/{$folderName}");
    }

    private function guessOutputPath(string $module): string
    {
        return base_path(Str::slug($module).'-todo');
    }

    private function parseUserStories(string $path): array
    {
        $content = File::get($path);
        $lines = explode("\n", $content);

        $result = [
            'title' => '',
            'epics' => [],
            'nfr' => [],
        ];

        $currentEpic = null;
        $currentUS = null;
        $currentSection = null;
        $inAcceptanceCriteria = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^#\s+(.+)$/', $trimmed, $matches)) {
                $result['title'] = $matches[1];

                continue;
            }

            if (preg_match('/^##\s+Epic\s+(\d+):\s*(.+)$/i', $trimmed, $matches)) {
                $currentEpic = (int) $matches[1];
                $result['epics'][$currentEpic] = [
                    'name' => $matches[2],
                    'user_stories' => [],
                ];
                $currentUS = null;
                $inAcceptanceCriteria = false;

                continue;
            }

            if (preg_match('/^##\s+Non-Functional\s+Requirements/i', $trimmed)) {
                $currentSection = 'nfr';
                $currentEpic = null;

                continue;
            }

            if ($currentSection === 'nfr' && preg_match('/^###\s+NFR-(\d+):\s*(.+)$/i', $trimmed, $matches)) {
                $nfrNum = (int) $matches[1];
                $result['nfr'][$nfrNum] = [
                    'name' => $matches[2],
                    'requirements' => [],
                ];

                continue;
            }

            if ($currentEpic && preg_match('/^###\s+US-[\d.]+:\s*(.+)$/i', $trimmed, $matches)) {
                preg_match('/US-([\d.]+)/', $trimmed, $usMatch);
                $currentUS = $usMatch[1];
                $result['epics'][$currentEpic]['user_stories'][$currentUS] = [
                    'title' => $matches[1],
                    'acceptance_criteria' => [],
                ];
                $inAcceptanceCriteria = false;

                continue;
            }

            if (preg_match('/^\*\*Acceptance\s+Criteria:\*\*$/i', $trimmed)) {
                $inAcceptanceCriteria = true;

                continue;
            }

            if ($currentEpic && $currentUS && $inAcceptanceCriteria) {
                if (preg_match('/^-\s+AC(\d+):\s*(.+)$/i', $trimmed, $matches)) {
                    $acNum = (int) $matches[1];
                    $result['epics'][$currentEpic]['user_stories'][$currentUS]['acceptance_criteria'][$acNum] = $matches[2];
                }
            }

            if ($inAcceptanceCriteria && preg_match('/^---$/', $trimmed)) {
                $inAcceptanceCriteria = false;
            }
        }

        return $result;
    }

    private function extractTestNames(array $testFiles): array
    {
        $tests = [];

        foreach ($testFiles as $file) {
            $content = File::get($file);
            $relativePath = Str::after($file, base_path('tests/'));
            $epicFolder = $this->extractEpicFromPath($file);

            preg_match_all('/(?:it|test)\s*\(\s*[\'"](.+?)[\'"]\s*,/m', $content, $matches);

            foreach ($matches[1] as $testName) {
                $tests[] = [
                    'name' => $testName,
                    'file' => basename($file),
                    'path' => $relativePath,
                    'epic' => $epicFolder,
                    'status' => 'unknown',
                ];
            }
        }

        return $tests;
    }

    private function runTests(string $testsPath): array
    {
        $tests = $this->extractTestNames($this->discoverTestFiles($testsPath));

        return $this->runTestsAndMatchResults($testsPath, $tests);
    }

    private function generateTodoFiles(string $module, string $outputPath): int
    {
        $count = 0;
        $skipped = 0;
        $prefix = $this->getStoriesPrefix($module);
        $force = $this->option('force');

        foreach ($this->parsedStories['epics'] as $epicNum => $epic) {
            $filename = "{$prefix}_TEST_TO_DO_EPIC_{$epicNum}.md";
            $filePath = "{$outputPath}/{$filename}";

            if (File::exists($filePath) && ! $force) {
                $this->line("   ⏭️  {$filename} (exists, use --force to overwrite)");
                $skipped++;

                continue;
            }

            $content = $this->generateEpicTodoContent($module, $epicNum, $epic);
            File::put($filePath, $content);
            $this->line("   ✓ {$filename}");
            $count++;
        }

        if (! empty($this->parsedStories['nfr'])) {
            $filename = "{$prefix}_TEST_TO_DO_NFR.md";
            $filePath = "{$outputPath}/{$filename}";

            if (File::exists($filePath) && ! $force) {
                $this->line("   ⏭️  {$filename} (exists, use --force to overwrite)");
                $skipped++;
            } else {
                $content = $this->generateNfrTodoContent($module);
                File::put($filePath, $content);
                $this->line("   ✓ {$filename}");
                $count++;
            }
        }

        if ($skipped > 0) {
            $this->newLine();
            $this->warn("   {$skipped} file(s) skipped. Use --force to overwrite existing files.");
        }

        return $count;
    }

    private function generateEpicTodoContent(string $module, int $epicNum, array $epic): string
    {
        $moduleName = Str::headline($module);
        $date = now()->format('Y-m-d');

        $epicTests = collect($this->testResults)->filter(fn ($t) => $t['epic'] === $epicNum);
        $stats = $this->calculateEpicStats($epicTests, $epic);
        $status = $this->generateStatusLine($stats);

        $usRange = $this->getUserStoryRange($epic);

        $md = "# Test Plan - Epic {$epicNum}: {$epic['name']}\n";
        $md .= "## {$moduleName} Module\n\n";
        $md .= "**Epic:** {$epic['name']}\n";
        $md .= "**User Stories:** {$usRange}\n";
        $md .= "**Generated:** {$date}\n";
        $md .= "**Status:** {$status}\n\n";
        $md .= "---\n\n";

        $md .= "## Test Results Summary\n\n";
        $md .= "| Test File | Passed | Failed | Unknown | Total |\n";
        $md .= "|-----------|--------|--------|---------|-------|\n";

        $testsByFile = $epicTests->groupBy('file');
        foreach ($testsByFile as $file => $tests) {
            $p = $tests->where('status', 'passed')->count();
            $f = $tests->where('status', 'failed')->count();
            $u = $tests->where('status', 'unknown')->count();
            $t = $tests->count();
            $md .= "| `{$file}` | {$p} | {$f} | {$u} | {$t} |\n";
        }
        if ($testsByFile->isEmpty()) {
            $md .= "| *(No tests found)* | 0 | 0 | 0 | 0 |\n";
        }
        $md .= "| **Total** | **{$stats['passed']}** | **{$stats['failed']}** | **{$stats['unknown']}** | **{$stats['total']}** |\n\n";
        $md .= "---\n\n";

        foreach ($epic['user_stories'] as $usNum => $us) {
            $md .= "## US-{$usNum}: {$us['title']}\n\n";

            foreach ($us['acceptance_criteria'] as $acNum => $acDesc) {
                $md .= "### AC{$acNum}: {$acDesc}\n\n";
                $md .= "```\n";

                $matchingTests = $this->findTestsForAC($epicTests, $acDesc, $us['title'], $acNum, $usNum);

                if ($matchingTests->isEmpty()) {
                    $md .= '- [ ] '.Str::lower($acDesc)."\n";
                } else {
                    foreach ($matchingTests as $test) {
                        $checkbox = $test['status'] === 'passed' ? '[x]' : '[ ]';
                        $statusLabel = match ($test['status']) {
                            'passed' => '✅ PASSING',
                            'failed' => '❌ FAILED',
                            default => '',
                        };
                        $md .= "- {$checkbox} {$test['name']} {$statusLabel}\n";
                    }
                }

                $md .= "```\n\n";
            }

            $md .= "---\n\n";
        }

        $md .= "## Estimated Totals\n\n";
        $md .= "| Category | Tests | Passed | Failed | Unknown | ACs Covered | ACs Missing |\n";
        $md .= "|----------|-------|--------|--------|---------|-------------|-------------|\n";
        $md .= "| **Total** | **{$stats['total']}** | **{$stats['passed']}** | **{$stats['failed']}** | **{$stats['unknown']}** | **{$stats['implemented_acs']}/{$stats['total_acs']}** | **{$stats['not_implemented']}** |\n\n";
        $md .= "---\n\n";
        $md .= "*Document Version: 1.0*\n";
        $md .= "*Last Updated: {$date}*\n";

        return $md;
    }

    private function generateNfrTodoContent(string $module): string
    {
        $moduleName = Str::headline($module);
        $date = now()->format('Y-m-d');

        $md = "# Test Plan - Non-Functional Requirements (NFR)\n";
        $md .= "## {$moduleName} Module\n\n";
        $md .= "**Category:** Non-Functional Requirements\n";
        $md .= "**Generated:** {$date}\n";
        $md .= "**Status:** ⚠️ Partially Implemented\n\n";
        $md .= "---\n\n";

        foreach ($this->parsedStories['nfr'] as $nfrNum => $nfr) {
            $md .= "## NFR-{$nfrNum}: {$nfr['name']}\n\n";
            $md .= "```\n";
            $md .= "- [ ] {$nfr['name']} tests not yet implemented\n";
            $md .= "```\n\n";
        }

        $md .= "---\n\n";
        $md .= "*Document Version: 1.0*\n";
        $md .= "*Last Updated: {$date}*\n";

        return $md;
    }

    private function countEpics(): int
    {
        return count($this->parsedStories['epics'] ?? []);
    }

    private function countUserStories(): int
    {
        $count = 0;
        foreach ($this->parsedStories['epics'] ?? [] as $epic) {
            $count += count($epic['user_stories'] ?? []);
        }

        return $count;
    }

    private function countACs(): int
    {
        $count = 0;
        foreach ($this->parsedStories['epics'] ?? [] as $epic) {
            foreach ($epic['user_stories'] ?? [] as $us) {
                $count += count($us['acceptance_criteria'] ?? []);
            }
        }

        return $count;
    }
}
