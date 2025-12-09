<?php

declare(strict_types=1);

namespace Larasai\UserStories\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LintUserStories extends Command
{
    protected $signature = 'stories:lint
        {module : Module name (e.g., attendance, project-management)}
        {--fix : Show suggestions for fixing issues}';

    protected $description = 'Validate User Stories markdown file against strict schema';

    private array $errors = [];

    private array $warnings = [];

    private string $filePath = '';

    private ?string $currentEpic = null;

    private ?string $currentUS = null;

    private ?int $currentACNum = null;

    private array $stats = [
        'epics' => 0,
        'stories' => 0,
        'acs' => 0,
    ];

    public function handle(): int
    {
        $module = $this->argument('module');
        $this->filePath = $this->findUserStoriesFile($module);

        if (! $this->filePath) {
            $this->error("User Stories file not found for module: {$module}");
            $this->line('Expected location: '.Str::slug($module).'-todo/{PREFIX}_USER_STORIES.md');

            return self::FAILURE;
        }

        $this->info("🔍 Linting User Stories: {$this->filePath}");
        $this->newLine();

        $content = File::get($this->filePath);
        $lines = explode("\n", $content);

        $this->validateStructure($lines);

        return $this->displayResults();
    }

    private function findUserStoriesFile(string $module): ?string
    {
        $folder = Str::slug($module).'-todo';

        $possibleNames = [
            'HR_'.Str::upper(Str::snake($module)).'_USER_STORIES.md',
            Str::upper(Str::snake($module)).'_USER_STORIES.md',
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

    private function validateStructure(array $lines): void
    {
        $lineNum = 0;
        $hasHeader = false;
        $currentEpicNum = null;
        $currentUSNum = null;
        $expectedEpic = 1;
        $expectedUSinEpic = 1;
        $expectedAC = 1;
        $inAcceptanceCriteria = false;
        $hasAsA = false;
        $hasIWantTo = false;
        $hasSoThat = false;
        $lastWasSeparator = false;
        $acCount = 0;

        foreach ($lines as $line) {
            $lineNum++;
            $trimmed = trim($line);

            if (preg_match('/^#\s+(.+)$/', $trimmed, $matches) && ! $hasHeader) {
                $hasHeader = true;
                if (! Str::contains($matches[1], 'Module')) {
                    $this->addWarning($lineNum, 'Document header should contain "Module"', $trimmed);
                }

                continue;
            }

            if (preg_match('/^##\s+Epic\s+(\d+):\s*(.*)$/i', $trimmed, $matches)) {
                $epicNum = (int) $matches[1];
                $epicTitle = trim($matches[2]);

                if ($currentUSNum && $acCount === 0) {
                    $this->addError($lineNum - 1, 'AC_MIN_ONE', "US-{$currentUSNum} has no Acceptance Criteria");
                }

                if ($currentEpicNum !== null && ! $lastWasSeparator) {
                    $this->addError($lineNum, 'SEPARATOR_REQUIRED', 'Missing --- before Epic header');
                }

                if ($epicNum !== $expectedEpic) {
                    $this->addError($lineNum, 'EPIC_SEQUENTIAL', "Expected Epic {$expectedEpic}, found Epic {$epicNum}");
                }

                if (empty($epicTitle)) {
                    $this->addError($lineNum, 'EPIC_TITLE', 'Epic title is required');
                }

                $currentEpicNum = $epicNum;
                $this->currentEpic = "Epic {$epicNum}";
                $expectedEpic = $epicNum + 1;
                $expectedUSinEpic = 1;
                $currentUSNum = null;
                $this->currentUS = null;
                $acCount = 0;
                $lastWasSeparator = false;
                $this->stats['epics']++;

                continue;
            }

            if (preg_match('/^###\s+US-([\d.]+):\s*(.*)$/i', $trimmed, $matches)) {
                $usNum = $matches[1];
                $usTitle = trim($matches[2]);

                if ($currentUSNum && $acCount === 0) {
                    $this->addError($lineNum - 1, 'AC_MIN_ONE', "US-{$currentUSNum} has no Acceptance Criteria");
                }

                if ($currentUSNum) {
                    if (! $hasAsA) {
                        $this->addError($lineNum, 'BODY_COMPLETE', "US-{$currentUSNum} missing '**As a**' line");
                    }
                    if (! $hasIWantTo) {
                        $this->addError($lineNum, 'BODY_COMPLETE', "US-{$currentUSNum} missing '**I want to**' line");
                    }
                    if (! $hasSoThat) {
                        $this->addError($lineNum, 'BODY_COMPLETE', "US-{$currentUSNum} missing '**So that**' line");
                    }
                }

                if ($currentUSNum !== null && ! $lastWasSeparator) {
                    $this->addError($lineNum, 'SEPARATOR_REQUIRED', 'Missing --- before User Story header');
                }

                if (! preg_match('/^\d+\.\d+$/', $usNum)) {
                    $this->addError($lineNum, 'US_FORMAT', "Invalid US format: US-{$usNum} (expected US-{Epic}.{Story})");
                } else {
                    $parts = explode('.', $usNum);
                    $usEpic = (int) $parts[0];
                    $usStory = (int) $parts[1];

                    if ($currentEpicNum && $usEpic !== $currentEpicNum) {
                        $this->addError($lineNum, 'US_FORMAT', "US-{$usNum} Epic mismatch (in Epic {$currentEpicNum})");
                    }

                    if ($usStory !== $expectedUSinEpic) {
                        $this->addError($lineNum, 'US_SEQUENTIAL', "Expected US-{$currentEpicNum}.{$expectedUSinEpic}, found US-{$usNum}");
                    }

                    $expectedUSinEpic = $usStory + 1;
                }

                if (empty($usTitle)) {
                    $this->addError($lineNum, 'US_TITLE', 'User Story title is required');
                }

                $currentUSNum = $usNum;
                $this->currentUS = "US-{$usNum}";
                $expectedAC = 1;
                $acCount = 0;
                $this->currentACNum = null;
                $inAcceptanceCriteria = false;
                $hasAsA = false;
                $hasIWantTo = false;
                $hasSoThat = false;
                $lastWasSeparator = false;
                $this->stats['stories']++;

                continue;
            }

            if (preg_match('/^\*\*As a[n]?\*\*\s+(.+)$/i', $trimmed)) {
                $hasAsA = true;
                $lastWasSeparator = false;

                continue;
            }

            if (preg_match('/^\*\*I want to\*\*\s+(.+)$/i', $trimmed)) {
                $hasIWantTo = true;
                $lastWasSeparator = false;

                continue;
            }

            if (preg_match('/^\*\*So that\*\*\s+(.+)$/i', $trimmed)) {
                $hasSoThat = true;
                $lastWasSeparator = false;

                continue;
            }

            if (preg_match('/^\*\*Acceptance\s+Criteria:\*\*$/i', $trimmed)) {
                $inAcceptanceCriteria = true;
                $lastWasSeparator = false;

                continue;
            }

            if ($inAcceptanceCriteria && preg_match('/^-\s+AC(\d+):\s*(.+)$/i', $trimmed, $matches)) {
                $acNum = (int) $matches[1];
                $acDesc = trim($matches[2]);

                if ($acNum !== $expectedAC) {
                    $this->addError($lineNum, 'AC_SEQUENTIAL', "Expected AC{$expectedAC}, found AC{$acNum}");
                }

                if (empty($acDesc)) {
                    $this->addError($lineNum, 'AC_DESCRIPTION', "AC{$acNum} description is required");
                }

                $expectedAC = $acNum + 1;
                $acCount++;
                $this->currentACNum = $acNum;
                $lastWasSeparator = false;
                $this->stats['acs']++;

                continue;
            }

            if ($inAcceptanceCriteria && preg_match('/^\s{2,}-\s+/', $line)) {
                $lastWasSeparator = false;

                continue;
            }

            if ($inAcceptanceCriteria && preg_match('/^-\s+(?!AC\d+:)(.+)$/', $trimmed, $matches)) {
                if (! Str::startsWith($trimmed, '- **') && ! empty($matches[1])) {
                    if (! preg_match('/^\*\*[^*]+:\*\*$/', $trimmed)) {
                        $this->addWarning(
                            $lineNum,
                            "Line doesn't follow AC format '- AC{N}: description'",
                            $trimmed,
                            'AC_FORMAT_WARNING'
                        );
                    }
                }
            }

            if ($trimmed === '---') {
                $lastWasSeparator = true;
                $inAcceptanceCriteria = false;

                continue;
            }

            if (preg_match('/^\*\*Technical\s+Notes:\*\*$/i', $trimmed)) {
                $inAcceptanceCriteria = false;
                $lastWasSeparator = false;

                continue;
            }

            if (preg_match('/^\*\*[^*]+:\*\*$/', $trimmed)) {
                $inAcceptanceCriteria = false;
                $lastWasSeparator = false;

                continue;
            }

            if (! empty($trimmed)) {
                $lastWasSeparator = false;
            }
        }

        if (! $hasHeader) {
            $this->addError(1, 'HEADER_REQUIRED', 'Document must start with H1 header (# Title)');
        }

        if ($currentUSNum && $acCount === 0) {
            $this->addError($lineNum, 'AC_MIN_ONE', "US-{$currentUSNum} has no Acceptance Criteria");
        }

        if ($currentUSNum) {
            if (! $hasAsA) {
                $this->addError($lineNum, 'BODY_COMPLETE', "US-{$currentUSNum} missing '**As a**' line");
            }
            if (! $hasIWantTo) {
                $this->addError($lineNum, 'BODY_COMPLETE', "US-{$currentUSNum} missing '**I want to**' line");
            }
            if (! $hasSoThat) {
                $this->addError($lineNum, 'BODY_COMPLETE', "US-{$currentUSNum} missing '**So that**' line");
            }
        }
    }

    private function addError(int $line, string $code, string $message): void
    {
        $this->errors[] = [
            'line' => $line,
            'code' => $code,
            'message' => $message,
            'epic' => $this->currentEpic,
            'us' => $this->currentUS,
            'ac' => $this->currentACNum ? "AC{$this->currentACNum}" : null,
        ];
    }

    private function addWarning(int $line, string $message, string $context = '', string $code = ''): void
    {
        $this->warnings[] = [
            'line' => $line,
            'code' => $code,
            'message' => $message,
            'context' => $context,
            'epic' => $this->currentEpic,
            'us' => $this->currentUS,
            'ac' => $this->currentACNum ? "AC{$this->currentACNum}" : null,
        ];
    }

    private function displayResults(): int
    {
        $relativePath = Str::after($this->filePath, base_path().'/');
        $errorCount = count($this->errors);
        $warningCount = count($this->warnings);

        if ($errorCount > 0) {
            $this->error("❌ Errors ({$errorCount}):");
            $this->newLine();

            $groupedErrors = $this->groupByLocation($this->errors);
            foreach ($groupedErrors as $location => $items) {
                $this->line("  <fg=white;options=bold>{$location}</>");
                foreach ($items as $error) {
                    $this->line("    <fg=red>✕</> Line {$error['line']}: <fg=red>[{$error['code']}]</> {$error['message']}");
                }
                $this->newLine();
            }
        }

        if ($warningCount > 0) {
            $this->warn("⚠️  Warnings ({$warningCount}):");
            $this->newLine();

            $groupedWarnings = $this->groupByLocation($this->warnings);
            foreach ($groupedWarnings as $location => $items) {
                $this->line("  <fg=white;options=bold>{$location}</>");
                foreach ($items as $warning) {
                    $acInfo = $warning['ac'] ? " ({$warning['ac']})" : '';
                    $this->line("    <fg=yellow>!</> Line {$warning['line']}{$acInfo}: {$warning['message']}");
                    if ($this->option('fix') && $warning['context']) {
                        $this->line("      <fg=gray>└─ {$warning['context']}</>");
                    }
                }
                $this->newLine();
            }
        }

        $this->displaySummary($relativePath, $errorCount, $warningCount);

        if ($errorCount > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function groupByLocation(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $location = $item['us'] ?? $item['epic'] ?? 'Document';
            if (! isset($grouped[$location])) {
                $grouped[$location] = [];
            }
            $grouped[$location][] = $item;
        }

        return $grouped;
    }

    private function displaySummary(string $relativePath, int $errorCount, int $warningCount): void
    {
        $this->newLine();
        $this->line('─────────────────────────────────────────────────────────────');
        $this->line('                        <fg=white;options=bold>SUMMARY</>');
        $this->line('─────────────────────────────────────────────────────────────');
        $this->newLine();

        $this->line("  <fg=gray>File:</> {$relativePath}");
        $this->newLine();

        $this->line('  <fg=white;options=bold>Document Statistics:</>');
        $this->line("    Epics:              {$this->stats['epics']}");
        $this->line("    User Stories:       {$this->stats['stories']}");
        $this->line("    Acceptance Criteria: {$this->stats['acs']}");
        $this->newLine();

        $this->line('  <fg=white;options=bold>Validation Result:</>');

        if ($errorCount === 0 && $warningCount === 0) {
            $this->line('    Status: <fg=green;options=bold>✅ PASSED</>');
        } elseif ($errorCount === 0) {
            $this->line("    Status: <fg=green;options=bold>✅ PASSED</> <fg=yellow>(with {$warningCount} warning".($warningCount > 1 ? 's' : '').')</>');
        } else {
            $this->line("    Status: <fg=red;options=bold>❌ FAILED</> <fg=red>({$errorCount} error".($errorCount > 1 ? 's' : '').')</>');
            if ($warningCount > 0) {
                $this->line("            <fg=yellow>({$warningCount} warning".($warningCount > 1 ? 's' : '').')</>');
            }
        }

        $this->newLine();

        if ($errorCount > 0 || $warningCount > 0) {
            $this->line('  <fg=white;options=bold>Issues by Location:</>');

            $allIssues = array_merge(
                array_map(fn ($e) => array_merge($e, ['type' => 'error']), $this->errors),
                array_map(fn ($w) => array_merge($w, ['type' => 'warning']), $this->warnings)
            );

            $byUS = [];
            foreach ($allIssues as $issue) {
                $us = $issue['us'] ?? 'Document-level';
                if (! isset($byUS[$us])) {
                    $byUS[$us] = ['errors' => 0, 'warnings' => 0];
                }
                if ($issue['type'] === 'error') {
                    $byUS[$us]['errors']++;
                } else {
                    $byUS[$us]['warnings']++;
                }
            }

            foreach ($byUS as $us => $counts) {
                $parts = [];
                if ($counts['errors'] > 0) {
                    $parts[] = "<fg=red>{$counts['errors']} error".($counts['errors'] > 1 ? 's' : '').'</>';
                }
                if ($counts['warnings'] > 0) {
                    $parts[] = "<fg=yellow>{$counts['warnings']} warning".($counts['warnings'] > 1 ? 's' : '').'</>';
                }
                $this->line("    {$us}: ".implode(', ', $parts));
            }

            $this->newLine();
        }

        if ($errorCount > 0) {
            $this->line('  <fg=gray>See stubs/USER_STORIES_SCHEMA.md for format reference.</>');
            $this->newLine();
        }

        $this->line('─────────────────────────────────────────────────────────────');
    }

    /**
     * Static method to validate User Stories file (for use by other commands)
     */
    public static function validate(string $module): array
    {
        $instance = new self;
        $instance->filePath = $instance->findUserStoriesFile($module);

        if (! $instance->filePath) {
            return [
                'valid' => false,
                'errors' => [['line' => 0, 'code' => 'FILE_NOT_FOUND', 'message' => 'User Stories file not found']],
                'warnings' => [],
                'path' => null,
                'stats' => ['epics' => 0, 'stories' => 0, 'acs' => 0],
            ];
        }

        $content = File::get($instance->filePath);
        $lines = explode("\n", $content);
        $instance->validateStructure($lines);

        return [
            'valid' => empty($instance->errors),
            'errors' => $instance->errors,
            'warnings' => $instance->warnings,
            'path' => $instance->filePath,
            'stats' => $instance->stats,
        ];
    }
}
