<?php

declare(strict_types=1);

namespace Larasai\UserStories\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Shared logic for matching tests to User Stories and Acceptance Criteria.
 *
 * Used by GenerateTestTodoFromStories and UpdateTodoStatus commands.
 */
trait MatchesTestsToAcceptanceCriteria
{
    protected string $userStoriesPrefix = '';

    /**
     * Find the User Stories file path and detect its prefix.
     */
    protected function guessStoriesPathWithPrefix(string $module): string
    {
        $folder = Str::slug($module).'-todo';

        // Try different naming conventions with their prefixes
        $possibleFiles = [
            // [filename, prefix]
            ['PM_USER_STORIES.md', 'PM'],
            ['HR_ATTENDANCE_USER_STORIES.md', 'HR_ATTENDANCE'],
            [Str::upper(Str::replace('-', '_', $module)).'_USER_STORIES.md', Str::upper(Str::replace('-', '_', $module))],
            ['HR_'.Str::upper(Str::replace('-', '_', $module)).'_USER_STORIES.md', 'HR_'.Str::upper(Str::replace('-', '_', $module))],
            ['USER_STORIES.md', Str::upper(Str::replace('-', '_', $module))],
        ];

        foreach ($possibleFiles as [$name, $prefix]) {
            $path = base_path("{$folder}/{$name}");
            if (File::exists($path)) {
                $this->userStoriesPrefix = $prefix;

                return $path;
            }
        }

        // Default prefix from module name
        $this->userStoriesPrefix = Str::upper(Str::replace('-', '_', $module));

        // Return first option as default (will show error if not found)
        return base_path("{$folder}/{$possibleFiles[0][0]}");
    }

    /**
     * Get the detected User Stories prefix.
     */
    protected function getStoriesPrefix(string $module): string
    {
        return $this->userStoriesPrefix ?: Str::upper(Str::replace('-', '_', Str::slug($module)));
    }

    /**
     * Find tests that match a specific Acceptance Criteria.
     *
     * Matching Priority:
     * 1. Full [US-X.X][ACn] tag match (strict)
     * 2. Just [US-X.X] tag → assigned to AC1 of that US only
     * 3. Keyword matching for untagged tests only (stricter threshold)
     */
    protected function findTestsForAC(Collection $tests, string $acDesc, string $usTitle, int $acNum = 0, string $usNum = ''): Collection
    {
        return $tests->filter(function ($test) use ($acDesc, $usTitle, $acNum, $usNum) {
            $testName = $test['name'];
            $testNameLower = Str::lower($testName);

            // PRIORITY 1: Full [US-X.X][ACn] tag matching (STRICT)
            if ($usNum && $acNum > 0) {
                $usPattern = preg_quote($usNum, '/');
                if (preg_match('/\[US-?'.$usPattern.'\]\s*\[AC-?'.$acNum.'\]/i', $testName)) {
                    return true;
                }
                if (preg_match('/\[US-?'.$usPattern.',?\s*AC-?'.$acNum.'\]/i', $testName)) {
                    return true;
                }
            }

            // PRIORITY 2: Just [US-X.X] tag - assign to first AC of that US
            if ($usNum && $acNum === 1) {
                $usPattern = preg_quote($usNum, '/');
                if (preg_match('/\[US-?'.$usPattern.'\](?!\s*\[AC)/i', $testName)) {
                    return true;
                }
            }

            // PRIORITY 3: Strict keyword matching (only for tests without tags)
            if (preg_match('/\[US-\d+\.\d+\]/i', $testName)) {
                return false;
            }

            $keywords = $this->extractKeywords($acDesc);
            $usKeywords = $this->extractKeywords($usTitle);

            $usMatchCount = 0;
            foreach ($usKeywords as $keyword) {
                if (Str::contains($testNameLower, $keyword)) {
                    $usMatchCount++;
                }
            }

            $acMatchCount = 0;
            foreach ($keywords as $keyword) {
                if (Str::contains($testNameLower, $keyword)) {
                    $acMatchCount++;
                }
            }

            return $usMatchCount >= 2 && $acMatchCount >= 1;
        });
    }

    /**
     * Find a matching test for a TODO item text.
     */
    protected function findMatchingTest(Collection $epicTests, string $todoText): ?array
    {
        $todoTextLower = Str::lower($todoText);

        $todoUsNum = null;
        $todoAcNum = null;
        if (preg_match('/\[US-?([\d.]+)\]/', $todoText, $matches)) {
            $todoUsNum = $matches[1];
        }
        if (preg_match('/\[AC-?(\d+)\]/', $todoText, $matches)) {
            $todoAcNum = (int) $matches[1];
        }

        // Strategy 1: If TODO has [US-X.X][ACn] tags, match test with same tags
        if ($todoUsNum && $todoAcNum) {
            $test = $epicTests->first(function ($t) use ($todoUsNum, $todoAcNum) {
                $usPattern = preg_quote($todoUsNum, '/');

                return preg_match('/\[US-?'.$usPattern.'\]\s*\[AC-?'.$todoAcNum.'\]/i', $t['name']);
            });

            if ($test) {
                $todoDescLower = Str::lower(preg_replace('/\[US-?[\d.]+\]\s*\[AC-?\d+\]\s*/i', '', $todoText));
                $testDescLower = Str::lower(preg_replace('/\[US-?[\d.]+\]\s*\[AC-?\d+\]\s*/i', '', $test['name']));

                if ($todoDescLower === $testDescLower || Str::contains($testDescLower, $todoDescLower) || Str::contains($todoDescLower, $testDescLower)) {
                    return $test;
                }
            }
        }

        // Strategy 2: Exact match on full text
        $test = $epicTests->first(fn ($t) => $t['name'] === $todoText);
        if ($test) {
            return $test;
        }

        // Strategy 3: Case-insensitive exact match
        $test = $epicTests->first(fn ($t) => Str::lower($t['name']) === $todoTextLower);
        if ($test) {
            return $test;
        }

        // Strategy 4: Check if test name is mentioned in parentheses
        if (preg_match('/\(([^)]+)\)/', $todoText, $matches)) {
            $parenthesisText = trim($matches[1]);
            $test = $epicTests->first(fn ($t) => Str::lower($t['name']) === Str::lower($parenthesisText));
            if ($test) {
                return $test;
            }
        }

        // Strategy 5: Only for untagged TODOs - keyword matching
        if ($todoUsNum) {
            return null;
        }

        $test = $epicTests->first(fn ($t) => Str::contains($todoTextLower, Str::lower($t['name'])));
        if ($test) {
            return $test;
        }

        $test = $epicTests->first(fn ($t) => Str::contains(Str::lower($t['name']), $todoTextLower));
        if ($test) {
            return $test;
        }

        // Strategy 6: Keyword matching
        $todoKeywords = $this->extractKeywords($todoText);
        if (count($todoKeywords) >= 2) {
            $bestMatch = null;
            $bestScore = 0;

            foreach ($epicTests as $t) {
                if (preg_match('/\[US-\d+\.\d+\]/i', $t['name'])) {
                    continue;
                }

                $testKeywords = $this->extractKeywords($t['name']);
                $matchCount = count(array_intersect($todoKeywords, $testKeywords));

                $threshold = min(3, ceil(count($todoKeywords) * 0.5));

                if ($matchCount >= $threshold && $matchCount > $bestScore) {
                    $bestScore = $matchCount;
                    $bestMatch = $t;
                }
            }

            if ($bestMatch) {
                return $bestMatch;
            }
        }

        return null;
    }

    /**
     * Find new tests that should be added to a specific AC.
     */
    protected function findNewTestsForAC(
        Collection $epicTests,
        array $testsInFile,
        array $processedTests,
        string $usNum,
        int $acNum,
        ?string $acDesc = null,
        ?string $usTitle = null
    ): Collection {
        return $epicTests->filter(function ($test) use ($testsInFile, $processedTests, $usNum, $acNum, $acDesc, $usTitle) {
            if (in_array($test['name'], $testsInFile) || in_array($test['name'], $processedTests)) {
                return false;
            }

            $testName = $test['name'];
            $usPattern = preg_quote($usNum, '/');

            if (preg_match('/\[US-?'.$usPattern.'\]\s*\[AC-?'.$acNum.'\]/i', $testName)) {
                return true;
            }

            if ($acNum === 1 && preg_match('/\[US-?'.$usPattern.'\](?!\s*\[AC)/i', $testName)) {
                return true;
            }

            if (preg_match('/\[US-\d+\.\d+\]/i', $testName)) {
                return false;
            }

            if ($acDesc && $usTitle) {
                $acKeywords = $this->extractKeywords($acDesc);
                $usKeywords = $this->extractKeywords($usTitle);
                $testKeywords = $this->extractKeywords($testName);

                $acMatchCount = count(array_intersect($acKeywords, $testKeywords));
                $usMatchCount = count(array_intersect($usKeywords, $testKeywords));

                if ($usMatchCount >= 2 && $acMatchCount >= 1) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Extract significant keywords from text for matching.
     */
    protected function extractKeywords(string $text): array
    {
        $text = Str::lower($text);
        $stopWords = ['can', 'be', 'is', 'are', 'the', 'a', 'an', 'to', 'for', 'of', 'with', 'on', 'in', 'and', 'or', 'not', 'has', 'have', 'should', 'must', 'will', 'would', 'could', 'may', 'might'];
        $words = preg_split('/[\s,\-()\/\[\]]+/', $text);

        return array_filter($words, fn ($w) => strlen($w) > 2 && ! in_array($w, $stopWords));
    }

    /**
     * Get status label for display.
     */
    protected function getStatusLabel(string $status): string
    {
        return match ($status) {
            'passed' => '✅ PASSING',
            'failed' => '❌ FAILED',
            'unknown' => '⚠️ STATUS UNKNOWN',
            default => '',
        };
    }

    /**
     * Calculate comprehensive test statistics for an Epic.
     */
    protected function calculateEpicStats(Collection $epicTests, array $epic): array
    {
        $passed = $epicTests->where('status', 'passed')->count();
        $failed = $epicTests->where('status', 'failed')->count();
        $unknown = $epicTests->where('status', 'unknown')->count();
        $total = $epicTests->count();

        $notImplemented = 0;
        $totalACs = 0;
        foreach ($epic['user_stories'] as $usNum => $us) {
            foreach ($us['acceptance_criteria'] as $acNum => $acDesc) {
                $totalACs++;
                $matching = $this->findTestsForAC($epicTests, $acDesc, $us['title'], $acNum, $usNum);
                if ($matching->isEmpty()) {
                    $notImplemented++;
                }
            }
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'unknown' => $unknown,
            'total' => $total,
            'not_implemented' => $notImplemented,
            'total_acs' => $totalACs,
            'implemented_acs' => $totalACs - $notImplemented,
        ];
    }

    /**
     * Generate a comprehensive status line for the Epic header.
     */
    protected function generateStatusLine(array $stats): string
    {
        $parts = [];
        $notFound = $stats['not_found'] ?? 0;

        if ($stats['passed'] > 0) {
            $parts[] = "{$stats['passed']} passed";
        }
        if ($stats['failed'] > 0) {
            $parts[] = "{$stats['failed']} failed";
        }
        if ($stats['unknown'] > 0) {
            $parts[] = "{$stats['unknown']} unknown";
        }
        if ($notFound > 0) {
            $parts[] = "{$notFound} not found";
        }

        if ($stats['total'] === 0 && $notFound === 0) {
            return '❌ Not Implemented (no tests found)';
        }

        if ($stats['failed'] > 0) {
            $emoji = '❌';
            $prefix = 'Failing';
        } elseif ($notFound > 0) {
            $emoji = '⚠️';
            $prefix = 'Incomplete';
        } elseif ($stats['unknown'] > 0) {
            $emoji = '⚠️';
            $prefix = 'Incomplete';
        } elseif ($stats['not_implemented'] > 0) {
            $emoji = '⚠️';
            $prefix = 'Partial';
        } else {
            $emoji = '✅';
            $prefix = 'Fully Implemented';
        }

        $statusText = implode(', ', $parts);

        if ($stats['not_implemented'] > 0 || $notFound > 0) {
            $statusText .= " | {$stats['implemented_acs']}/{$stats['total_acs']} ACs covered";
        }

        return "{$emoji} {$prefix} ({$statusText})";
    }

    /**
     * Extract Epic number from test file path.
     */
    protected function extractEpicFromPath(string $path): ?int
    {
        if (preg_match('/Epic[-_](\d+)/i', $path, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/Security/i', $path)) {
            return 0;
        }
        if (preg_match('/CrossCutting/i', $path)) {
            return null;
        }

        return null;
    }

    /**
     * Get User Story range string for an Epic.
     */
    protected function getUserStoryRange(array $epic): string
    {
        $nums = array_keys($epic['user_stories']);
        if (empty($nums)) {
            return 'N/A';
        }
        $first = reset($nums);
        $last = end($nums);

        return "US-{$first} to US-{$last}";
    }

    /**
     * Recursively parse JUnit XML to extract test results.
     */
    protected function parseJunitTestcases(\SimpleXMLElement $element, array &$passedTests, array &$failedTests): void
    {
        foreach ($element->testcase ?? [] as $testcase) {
            $name = (string) $testcase['name'];

            if (preg_match('/→\s*it\s+(.+)$/', $name, $matches)) {
                $cleanName = trim($matches[1]);
            } elseif (preg_match('/it\s+(.+)$/', $name, $matches)) {
                $cleanName = trim($matches[1]);
            } else {
                $cleanName = $name;
            }

            if (! empty($cleanName)) {
                if (isset($testcase->failure) || isset($testcase->error)) {
                    $failedTests[] = $cleanName;
                } else {
                    $passedTests[] = $cleanName;
                }
            }
        }

        foreach ($element->testsuite ?? [] as $testsuite) {
            $this->parseJunitTestcases($testsuite, $passedTests, $failedTests);
        }
    }

    /**
     * Run tests and match results to test array.
     */
    protected function runTestsAndMatchResults(string $testTarget, array $tests): array
    {
        $junitFile = '/tmp/pest-results-'.uniqid().'.xml';

        $command = sprintf(
            'php vendor/bin/pest %s --log-junit=%s 2>&1',
            escapeshellarg($testTarget),
            escapeshellarg($junitFile)
        );

        $result = Process::timeout(600)->path(base_path())->run($command);

        $junitExists = File::exists($junitFile);
        $usedJunit = false;

        if ($junitExists) {
            $passedTests = [];
            $failedTests = [];

            try {
                $xml = simplexml_load_file($junitFile);
                $this->parseJunitTestcases($xml, $passedTests, $failedTests);
                $usedJunit = true;

                foreach ($tests as &$test) {
                    $test['status'] = $this->matchTestStatus($test['name'], $passedTests, $failedTests);
                }
            } catch (\Exception $e) {
                $this->warn("   ⚠️  Could not parse JUnit XML: {$e->getMessage()}");
                $usedJunit = false;
            }

            File::delete($junitFile);
        }

        if (! $usedJunit) {
            $this->warn('   ⚠️  Falling back to console output parsing (less accurate)');
            $output = $result->output();

            foreach ($tests as &$test) {
                $test['status'] = $this->matchTestStatusFromConsole($test['name'], $output);
            }
        }

        return $tests;
    }

    /**
     * Match a test name against passed/failed test arrays.
     */
    protected function matchTestStatus(string $testName, array $passedTests, array $failedTests): string
    {
        if (in_array($testName, $passedTests, true)) {
            return 'passed';
        }

        if (in_array($testName, $failedTests, true)) {
            return 'failed';
        }

        foreach ($passedTests as $passedName) {
            if (Str::contains($passedName, $testName) || Str::contains($testName, $passedName)) {
                return 'passed';
            }
        }

        foreach ($failedTests as $failedName) {
            if (Str::contains($failedName, $testName) || Str::contains($testName, $failedName)) {
                return 'failed';
            }
        }

        return 'unknown';
    }

    /**
     * Match a test name against console output (fallback method).
     */
    protected function matchTestStatusFromConsole(string $testName, string $output): string
    {
        $testNameQuoted = preg_quote($testName, '/');

        if (preg_match("/✓.*{$testNameQuoted}/i", $output) || preg_match("/PASS.*{$testNameQuoted}/i", $output)) {
            return 'passed';
        }

        if (preg_match("/✕.*{$testNameQuoted}/i", $output) || preg_match("/FAIL.*{$testNameQuoted}/i", $output)) {
            return 'failed';
        }

        return 'unknown';
    }

    /**
     * Discover all test files in a directory.
     */
    protected function discoverTestFiles(string $path): array
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
}
