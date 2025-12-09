<?php

declare(strict_types=1);

namespace Larasai\UserStories\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LintTestNames extends Command
{
    protected $signature = 'test:lint-names
        {module? : Specific module to lint (e.g., attendance, project-management)}
        {--fix : Show suggestions for fixing invalid test names}
        {--strict : Fail on any test without proper AC tags}';

    protected $description = 'Validate test naming conventions against AC-tagging requirements';

    private array $validUserStories = [];

    private array $errors = [];

    private array $warnings = [];

    public function handle(): int
    {
        $module = $this->argument('module');

        $this->info('🔍 Linting test names for AC-tagging compliance...');
        $this->newLine();

        if ($module) {
            $this->lintModule($module);
        } else {
            $this->lintAllModules();
        }

        $this->displayResults();

        if (! empty($this->errors)) {
            return self::FAILURE;
        }

        if ($this->option('strict') && ! empty($this->warnings)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function lintAllModules(): void
    {
        $featureTestsPath = base_path('tests/Feature');

        if (! File::isDirectory($featureTestsPath)) {
            $this->error('tests/Feature directory not found');

            return;
        }

        $directories = File::directories($featureTestsPath);

        foreach ($directories as $dir) {
            $moduleName = basename($dir);

            $storiesPath = $this->findUserStoriesFile($moduleName);
            if ($storiesPath) {
                $this->lintModule(Str::kebab($moduleName));
            }
        }
    }

    private function lintModule(string $module): void
    {
        $moduleName = Str::studly(Str::replace('-', ' ', $module));
        $testsPath = base_path("tests/Feature/{$moduleName}");
        $storiesPath = $this->findUserStoriesFile($moduleName);

        if (! $storiesPath) {
            $this->line("⏭️  Skipping {$module}: No USER_STORIES.md file found");

            return;
        }

        if (! File::isDirectory($testsPath)) {
            $this->line("⏭️  Skipping {$module}: No tests/Feature/{$moduleName} directory");

            return;
        }

        $this->info("📋 Linting module: {$module}");
        $this->line("   User Stories: {$storiesPath}");
        $this->line("   Tests: {$testsPath}");

        $this->validUserStories = $this->parseUserStories($storiesPath);

        $testFiles = $this->discoverTestFiles($testsPath);
        $this->lintTestFiles($testFiles, $module);

        $this->newLine();
    }

    private function findUserStoriesFile(string $moduleName): ?string
    {
        $folder = Str::slug(Str::kebab($moduleName)).'-todo';

        $possibleNames = [
            'HR_'.Str::upper(Str::snake($moduleName)).'_USER_STORIES.md',
            Str::upper(Str::snake($moduleName)).'_USER_STORIES.md',
            'PM_USER_STORIES.md',
            'USER_STORIES.md',
        ];

        foreach ($possibleNames as $name) {
            $path = base_path("{$folder}/{$name}");
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function parseUserStories(string $path): array
    {
        $content = File::get($path);
        $lines = explode("\n", $content);

        $userStories = [];
        $currentUS = null;
        $inAcceptanceCriteria = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^###\s+US-([\d.]+):\s*(.+)$/i', $trimmed, $matches)) {
                $currentUS = $matches[1];
                $userStories[$currentUS] = [
                    'title' => $matches[2],
                    'acceptance_criteria' => [],
                ];
                $inAcceptanceCriteria = false;

                continue;
            }

            if (preg_match('/^\*\*Acceptance\s+Criteria:\*\*$/i', $trimmed)) {
                $inAcceptanceCriteria = true;

                continue;
            }

            if ($currentUS && $inAcceptanceCriteria) {
                if (preg_match('/^-\s+AC(\d+):\s*(.+)$/i', $trimmed, $matches)) {
                    $acNum = (int) $matches[1];
                    $userStories[$currentUS]['acceptance_criteria'][$acNum] = $matches[2];
                }
            }

            if ($inAcceptanceCriteria && preg_match('/^---$/', $trimmed)) {
                $inAcceptanceCriteria = false;
            }
        }

        return $userStories;
    }

    private function discoverTestFiles(string $path): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && Str::endsWith($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function lintTestFiles(array $testFiles, string $module): void
    {
        $totalTests = 0;
        $validTests = 0;
        $invalidTests = 0;

        foreach ($testFiles as $file) {
            $content = File::get($file);
            $relativePath = Str::after($file, base_path().'/');

            preg_match_all('/(?:it|test)\s*\(\s*[\'"](.+?)[\'"]\s*,/m', $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as $match) {
                $testName = $match[0];
                $offset = $match[1];
                $lineNumber = $this->getLineNumber($content, $offset);
                $totalTests++;

                $validation = $this->validateTestName($testName);

                if ($validation['valid']) {
                    $validTests++;
                } else {
                    $invalidTests++;

                    if ($validation['type'] === 'error') {
                        $this->errors[] = [
                            'file' => $relativePath,
                            'line' => $lineNumber,
                            'test' => $testName,
                            'message' => $validation['message'],
                            'suggestion' => $validation['suggestion'] ?? null,
                        ];
                    } else {
                        $this->warnings[] = [
                            'file' => $relativePath,
                            'line' => $lineNumber,
                            'test' => $testName,
                            'message' => $validation['message'],
                            'suggestion' => $validation['suggestion'] ?? null,
                        ];
                    }
                }
            }
        }

        $this->line("   Total: {$totalTests} tests | Valid: {$validTests} | Invalid: {$invalidTests}");
    }

    private function validateTestName(string $testName): array
    {
        $pattern = '/^\[US-([\d.]+)\]\[AC(\d+)\]\s+.+$/';

        if (! preg_match($pattern, $testName, $matches)) {
            if (preg_match('/\[US-([\d.]+)\]/', $testName) && ! preg_match('/\[AC\d+\]/', $testName)) {
                return [
                    'valid' => false,
                    'type' => 'error',
                    'message' => 'Missing AC tag (has US tag)',
                    'suggestion' => $this->suggestACTag($testName),
                ];
            }

            if (preg_match('/\[AC(\d+)\]/', $testName) && ! preg_match('/\[US-[\d.]+\]/', $testName)) {
                return [
                    'valid' => false,
                    'type' => 'error',
                    'message' => 'Missing US tag (has AC tag)',
                    'suggestion' => '[US-?.?]'.$testName,
                ];
            }

            return [
                'valid' => false,
                'type' => 'warning',
                'message' => 'Missing [US-X.X][AC{n}] tags',
                'suggestion' => '[US-?.?][AC?] '.$testName,
            ];
        }

        $usNum = $matches[1];
        $acNum = (int) $matches[2];

        if (! isset($this->validUserStories[$usNum])) {
            return [
                'valid' => false,
                'type' => 'error',
                'message' => "User Story US-{$usNum} not found in USER_STORIES.md",
                'suggestion' => 'Available: US-'.implode(', US-', array_keys($this->validUserStories)),
            ];
        }

        if (! isset($this->validUserStories[$usNum]['acceptance_criteria'][$acNum])) {
            $validACs = array_keys($this->validUserStories[$usNum]['acceptance_criteria']);

            return [
                'valid' => false,
                'type' => 'error',
                'message' => "AC{$acNum} not found in US-{$usNum}",
                'suggestion' => 'Available ACs for US-'.$usNum.': AC'.implode(', AC', $validACs),
            ];
        }

        return ['valid' => true];
    }

    private function suggestACTag(string $testName): string
    {
        if (preg_match('/(\[US-[\d.]+\])/', $testName, $matches)) {
            $usTag = $matches[1];
            $rest = trim(str_replace($usTag, '', $testName));

            return "{$usTag}[AC?] {$rest}";
        }

        return $testName;
    }

    private function getLineNumber(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    private function displayResults(): void
    {
        if (empty($this->errors) && empty($this->warnings)) {
            $this->newLine();
            $this->info('✅ All tests have valid AC-tagged names!');

            return;
        }

        if (! empty($this->errors)) {
            $this->newLine();
            $this->error('❌ Errors ('.count($this->errors).'):');
            $this->newLine();

            foreach ($this->errors as $error) {
                $this->line("  <fg=red>✕</> {$error['file']}:{$error['line']}");
                $this->line("    Test: <fg=yellow>{$error['test']}</>");
                $this->line("    Issue: {$error['message']}");
                if ($this->option('fix') && $error['suggestion']) {
                    $this->line("    <fg=green>Suggestion:</> {$error['suggestion']}");
                }
                $this->newLine();
            }
        }

        if (! empty($this->warnings)) {
            $this->newLine();
            $this->warn('⚠️  Warnings ('.count($this->warnings).'):');
            $this->newLine();

            foreach ($this->warnings as $warning) {
                $this->line("  <fg=yellow>!</> {$warning['file']}:{$warning['line']}");
                $this->line("    Test: <fg=yellow>{$warning['test']}</>");
                $this->line("    Issue: {$warning['message']}");
                if ($this->option('fix') && $warning['suggestion']) {
                    $this->line("    <fg=green>Suggestion:</> {$warning['suggestion']}");
                }
                $this->newLine();
            }
        }

        $this->newLine();
        $this->line('Run with --fix to see suggested corrections');
        if (! empty($this->warnings) && ! $this->option('strict')) {
            $this->line('Run with --strict to fail on warnings');
        }
    }
}
