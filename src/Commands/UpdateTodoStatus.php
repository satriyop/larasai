<?php

declare(strict_types=1);

namespace Larasai\UserStories\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Larasai\UserStories\Concerns\MatchesTestsToAcceptanceCriteria;

use function Laravel\Prompts\confirm;

class UpdateTodoStatus extends Command
{
    use MatchesTestsToAcceptanceCriteria;

    protected $signature = 'test:update-todo-status
        {module : Module name (e.g., attendance, project-management)}
        {--epic= : Only update specific Epic number (e.g., --epic=1)}
        {--stories= : Path to user stories markdown file}
        {--tests= : Path to tests directory}
        {--output= : Output directory for TODO files}';

    protected $description = 'Update test pass/fail status in existing TODO files without changing structure';

    private array $testResults = [];

    private array $parsedStories = [];

    private array $usToEpicMap = [];

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
        $epicFilter = $this->option('epic') ? (int) $this->option('epic') : null;

        $epicInfo = $epicFilter ? " (Epic {$epicFilter} only)" : '';
        $this->info("🔄 Updating Test TODO status for module: {$module}{$epicInfo}");
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
        $this->buildUsToEpicMap();
        $this->line("   Found {$this->countEpics()} Epics, {$this->countUserStories()} User Stories");

        $this->info('2️⃣  Running tests...');
        $this->testResults = $this->runTests($testsPath, $epicFilter);
        $passed = collect($this->testResults)->where('status', 'passed')->count();
        $failed = collect($this->testResults)->where('status', 'failed')->count();
        $total = count($this->testResults);
        $this->line("   Results: {$passed} passed, {$failed} failed, {$total} total");

        $this->info('3️⃣  Updating TODO files...');
        File::ensureDirectoryExists($outputPath);
        $this->updateTodoFiles($module, $outputPath, $epicFilter);

        $this->newLine();
        $this->info('✅ TODO status updated successfully!');

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
            'epics' => [],
            'nfr' => [],
        ];

        $currentEpic = null;
        $currentUS = null;
        $currentSection = null;
        $inAcceptanceCriteria = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

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

    private function buildUsToEpicMap(): void
    {
        foreach ($this->parsedStories['epics'] as $epicNum => $epic) {
            foreach ($epic['user_stories'] as $usNum => $us) {
                $this->usToEpicMap[$usNum] = $epicNum;
            }
        }
    }

    private function runTests(string $testsPath, ?int $epicFilter = null): array
    {
        $testTarget = $testsPath;
        if ($epicFilter !== null) {
            $epicPathDash = "{$testsPath}/Epic-{$epicFilter}";
            $epicPathUnderscore = "{$testsPath}/Epic_{$epicFilter}";

            if (File::isDirectory($epicPathDash)) {
                $testTarget = $epicPathDash;
            } elseif (File::isDirectory($epicPathUnderscore)) {
                $testTarget = $epicPathUnderscore;
            }
        }

        $tests = $this->extractTestNames($testsPath, $epicFilter);

        return $this->runTestsAndMatchResults($testTarget, $tests);
    }

    private function extractTestNames(string $testsPath, ?int $epicFilter = null): array
    {
        $tests = [];
        $testFiles = $this->discoverTestFiles($testsPath);

        foreach ($testFiles as $file) {
            $content = File::get($file);
            $relativePath = Str::after($file, base_path('tests/'));
            $epicFromPath = $this->extractEpicFromPath($file);

            preg_match_all('/(?:it|test)\s*\(\s*[\'"](.+?)[\'"]\s*,/m', $content, $matches);

            foreach ($matches[1] as $testName) {
                $epicFromTag = $this->extractEpicFromUsTag($testName);
                $testEpic = $epicFromPath ?? $epicFromTag;

                if ($epicFilter !== null && $testEpic !== $epicFilter) {
                    continue;
                }

                $tests[] = [
                    'name' => $testName,
                    'file' => basename($file),
                    'path' => $relativePath,
                    'epic' => $testEpic,
                    'epic_from_path' => $epicFromPath,
                    'epic_from_tag' => $epicFromTag,
                    'status' => 'unknown',
                ];
            }
        }

        return $tests;
    }

    private function extractEpicFromUsTag(string $testName): ?int
    {
        if (preg_match('/\[US-([\d.]+)\]/', $testName, $matches)) {
            $usNum = $matches[1];
            if (isset($this->usToEpicMap[$usNum])) {
                return $this->usToEpicMap[$usNum];
            }
        }

        return null;
    }

    private function updateTodoFiles(string $module, string $outputPath, ?int $epicFilter = null): void
    {
        $prefix = $this->getStoriesPrefix($module);
        $testsByEpic = collect($this->testResults)->groupBy('epic');
        $processedTests = [];

        foreach ($this->parsedStories['epics'] as $epicNum => $epic) {
            if ($epicFilter !== null && $epicNum !== $epicFilter) {
                continue;
            }
            $filename = "{$prefix}_TEST_TO_DO_EPIC_{$epicNum}.md";
            $filePath = "{$outputPath}/{$filename}";

            $epicTests = $testsByEpic->get($epicNum, collect());

            if (File::exists($filePath)) {
                $result = $this->updateExistingTodoFile($filePath, $epicTests, $epic, $epicNum, $module);
                $processedTests = array_merge($processedTests, $result['processed']);
                $this->line("   ✓ {$filename} (updated: {$result['updated']}, new: {$result['new']}, not found: {$result['not_found']})");
            } else {
                $content = $this->generateNewTodoFile($module, $epicNum, $epic, $epicTests);
                File::put($filePath, $content);
                foreach ($epicTests as $test) {
                    $processedTests[] = $test['name'];
                }
                $this->line("   ✓ {$filename} (created)");
            }
        }

        if ($epicFilter === null && ! empty($this->parsedStories['nfr'])) {
            $filename = "{$prefix}_TEST_TO_DO_NFR.md";
            $filePath = "{$outputPath}/{$filename}";
            $nfrTests = $testsByEpic->get(0, collect());

            if (File::exists($filePath)) {
                $result = $this->updateExistingNfrFile($filePath, $nfrTests);
                $processedTests = array_merge($processedTests, $result['processed']);
                $this->line("   ✓ {$filename} (updated)");
            } else {
                $content = $this->generateNewNfrFile($module, $nfrTests);
                File::put($filePath, $content);
                $this->line("   ✓ {$filename} (created)");
            }
        }

        if ($epicFilter === null) {
            $unmatchedTests = collect($this->testResults)->filter(function ($test) use ($processedTests) {
                return ! in_array($test['name'], $processedTests) && $test['epic'] === null;
            });

            if ($unmatchedTests->isNotEmpty()) {
                $this->handleUnmatchedTests($outputPath, $prefix, $unmatchedTests);
            }
        }
    }

    private function updateExistingTodoFile(string $filePath, Collection $epicTests, array $epic, int $epicNum, string $module): array
    {
        $content = File::get($filePath);
        $lines = explode("\n", $content);

        $updated = 0;
        $notFound = 0;
        $newTests = 0;
        $processedTests = [];
        $testsInFile = [];

        $currentUS = null;
        $currentUSTitle = null;
        $currentAC = null;
        $currentACDesc = null;
        $inCodeBlock = false;

        foreach ($lines as $line) {
            if (preg_match('/^##\s+US-([\d.]+):\s*(.*)$/', $line, $matches)) {
                $currentUS = $matches[1];
                $currentUSTitle = trim($matches[2] ?? '');
            }
            if (preg_match('/^###\s+AC(\d+):\s*(.*)$/', $line, $matches)) {
                $currentAC = (int) $matches[1];
                $currentACDesc = trim($matches[2] ?? '');
            }
            if (preg_match('/^```/', $line)) {
                $inCodeBlock = ! $inCodeBlock;
            }

            if ($inCodeBlock && preg_match('/^-\s+\[[ x]\]\s+(.+?)(?:\s+(?:✅|❌|⚠️|🆕).*)?$/', $line, $matches)) {
                $todoText = trim($matches[1]);
                $testsInFile[] = $todoText;

                $test = $this->findMatchingTest($epicTests, $todoText);
                if (! $test) {
                    $notFound++;
                }
            }
        }

        $stats = $this->calculateEpicStats($epicTests, $epic);
        $stats['not_found'] = $notFound;

        $newLines = [];
        $currentUS = null;
        $currentUSTitle = null;
        $currentAC = null;
        $currentACDesc = null;
        $inCodeBlock = false;

        foreach ($lines as $line) {
            if (preg_match('/^##\s+US-([\d.]+):\s*(.*)$/', $line, $matches)) {
                $currentUS = $matches[1];
                $currentUSTitle = trim($matches[2] ?? '');
            }

            if (preg_match('/^###\s+AC(\d+):\s*(.*)$/', $line, $matches)) {
                $currentAC = (int) $matches[1];
                $currentACDesc = trim($matches[2] ?? '');
            }

            if (preg_match('/^```/', $line)) {
                $inCodeBlock = ! $inCodeBlock;

                if (! $inCodeBlock && $currentUS && $currentAC) {
                    $newTestsForAC = $this->findNewTestsForAC($epicTests, $testsInFile, $processedTests, $currentUS, $currentAC, $currentACDesc, $currentUSTitle);
                    foreach ($newTestsForAC as $test) {
                        $checkbox = $test['status'] === 'passed' ? '[x]' : '[ ]';
                        $statusLabel = $this->getStatusLabel($test['status']);
                        $newLines[] = "- {$checkbox} {$test['name']} {$statusLabel} 🆕 NEW";
                        $processedTests[] = $test['name'];
                        $newTests++;
                    }
                }

                $newLines[] = $line;

                continue;
            }

            if ($inCodeBlock && preg_match('/^-\s+\[[ x]\]\s+(.+?)(?:\s+(?:✅|❌|⚠️|🆕).*)?$/', $line, $matches)) {
                $todoText = trim($matches[1]);

                $test = $this->findMatchingTest($epicTests, $todoText);

                if ($test) {
                    $checkbox = $test['status'] === 'passed' ? '[x]' : '[ ]';
                    $statusLabel = $this->getStatusLabel($test['status']);
                    $newLines[] = "- {$checkbox} {$todoText} {$statusLabel}";
                    $processedTests[] = $test['name'];
                    $updated++;
                } else {
                    $newLines[] = "- [ ] {$todoText} ⚠️ TEST NOT FOUND";
                }

                continue;
            }

            if (preg_match('/^\*\*Status:\*\*/', $line)) {
                $newLines[] = '**Status:** '.$this->generateStatusLine($stats);

                continue;
            }

            if (preg_match('/^\|\s+\*\*Total\*\*/', $line)) {
                $newLines[] = "| **Total** | **{$stats['passed']}** | **{$stats['failed']}** | **{$stats['unknown']}** | **{$stats['total']}** |";

                continue;
            }

            if (preg_match('/^\|\s+`(.+?\.php)`/', $line, $matches)) {
                $fileName = $matches[1];
                $fileTests = $epicTests->where('file', $fileName);
                $p = $fileTests->where('status', 'passed')->count();
                $f = $fileTests->where('status', 'failed')->count();
                $u = $fileTests->where('status', 'unknown')->count();
                $t = $fileTests->count();
                $newLines[] = "| `{$fileName}` | {$p} | {$f} | {$u} | {$t} |";

                continue;
            }

            $newLines[] = $line;
        }

        File::put($filePath, implode("\n", $newLines));

        return [
            'updated' => $updated,
            'not_found' => $notFound,
            'new' => $newTests,
            'processed' => $processedTests,
        ];
    }

    private function updateExistingNfrFile(string $filePath, Collection $nfrTests): array
    {
        $content = File::get($filePath);
        $lines = explode("\n", $content);
        $newLines = [];
        $processedTests = [];

        foreach ($lines as $line) {
            if (preg_match('/^-\s+\[[ x]\]\s+(.+?)(?:\s+(?:✅|❌|⚠️).*)?$/', $line, $matches)) {
                $testName = trim($matches[1]);
                $test = $nfrTests->first(fn ($t) => $t['name'] === $testName);

                if ($test) {
                    $checkbox = $test['status'] === 'passed' ? '[x]' : '[ ]';
                    $statusLabel = $this->getStatusLabel($test['status']);
                    $newLines[] = "- {$checkbox} {$testName} {$statusLabel}";
                    $processedTests[] = $testName;
                } else {
                    $newLines[] = "- [ ] {$testName} ⚠️ TEST NOT FOUND";
                }

                continue;
            }

            $newLines[] = $line;
        }

        File::put($filePath, implode("\n", $newLines));

        return ['processed' => $processedTests];
    }

    private function handleUnmatchedTests(string $outputPath, string $prefix, Collection $unmatchedTests): void
    {
        $filename = "{$prefix}_TEST_TO_DO_UNMATCHED.md";
        $filePath = "{$outputPath}/{$filename}";

        $date = now()->format('Y-m-d');
        $passed = $unmatchedTests->where('status', 'passed')->count();
        $failed = $unmatchedTests->where('status', 'failed')->count();
        $total = $unmatchedTests->count();

        $md = "# Unmatched Tests\n\n";
        $md .= "**Generated:** {$date}\n";
        $md .= "**Status:** Tests that could not be matched to any Epic/AC\n\n";
        $md .= "---\n\n";
        $md .= "## Summary\n\n";
        $md .= "| Passed | Failed | Total |\n";
        $md .= "|--------|--------|-------|\n";
        $md .= "| {$passed} | {$failed} | {$total} |\n\n";
        $md .= "---\n\n";
        $md .= "## Tests\n\n";
        $md .= "```\n";

        foreach ($unmatchedTests as $test) {
            $checkbox = $test['status'] === 'passed' ? '[x]' : '[ ]';
            $statusLabel = $this->getStatusLabel($test['status']);
            $md .= "- {$checkbox} {$test['name']} {$statusLabel} 🆕 NEW\n";
        }

        $md .= "```\n\n";
        $md .= "---\n\n";
        $md .= "*These tests need [US-X.X][AC{n}] tags or should be moved to appropriate Epic folders*\n";

        File::put($filePath, $md);
        $this->line("   ⚠️  {$filename} ({$total} unmatched tests)");
    }

    private function generateNewTodoFile(string $module, int $epicNum, array $epic, Collection $epicTests): string
    {
        $moduleName = Str::headline($module);
        $date = now()->format('Y-m-d');

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
                        $statusLabel = $this->getStatusLabel($test['status']);
                        $md .= "- {$checkbox} {$test['name']} {$statusLabel}\n";
                    }
                }

                $md .= "```\n\n";
            }

            $md .= "---\n\n";
        }

        $md .= "*Document Version: 1.0*\n";
        $md .= "*Last Updated: {$date}*\n";

        return $md;
    }

    private function generateNewNfrFile(string $module, Collection $nfrTests): string
    {
        $moduleName = Str::headline($module);
        $date = now()->format('Y-m-d');

        $passed = $nfrTests->where('status', 'passed')->count();
        $failed = $nfrTests->where('status', 'failed')->count();

        $status = match (true) {
            $nfrTests->isEmpty() => '❌ Not Implemented',
            $failed > 0 => "⚠️ Partially Implemented ({$passed} passed, {$failed} failed)",
            default => "✅ Fully Implemented ({$passed} passed, 0 failed)",
        };

        $md = "# Test Plan - Non-Functional Requirements (NFR)\n";
        $md .= "## {$moduleName} Module\n\n";
        $md .= "**Category:** Non-Functional Requirements\n";
        $md .= "**Generated:** {$date}\n";
        $md .= "**Status:** {$status}\n\n";
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
}
