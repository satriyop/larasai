<?php

declare(strict_types=1);

namespace Larasai\UserStories\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;
use function Laravel\Prompts\warning;

class CreateUserStory extends Command
{
    protected $signature = 'stories:create
        {module : Module name (e.g., attendance, project-management)}
        {--epic : Generate a complete Epic with multiple User Stories}';

    protected $description = 'Interactively generate User Stories following the strict schema';

    private string $filePath = '';

    private string $prefix = '';

    private string $moduleName = '';

    public function handle(): int
    {
        $module = $this->argument('module');
        $this->moduleName = Str::title(str_replace('-', ' ', $module));
        $this->prefix = $this->determinePrefix($module);

        $folder = Str::slug($module).'-todo';
        $this->filePath = base_path("{$folder}/{$this->prefix}_USER_STORIES.md");

        if (! File::isDirectory(base_path($folder))) {
            File::makeDirectory(base_path($folder), 0755, true);
        }

        info("Creating User Stories for: {$this->moduleName} Module");

        if ($this->option('epic')) {
            return $this->createEpic();
        }

        return $this->createSingleUserStory();
    }

    private function determinePrefix(string $module): string
    {
        $prefixMap = [
            'attendance' => 'HR_ATTENDANCE',
            'project-management' => 'PM',
            'hr' => 'HR',
        ];

        return $prefixMap[strtolower($module)]
            ?? strtoupper(Str::snake($module));
    }

    private function createEpic(): int
    {
        $existingContent = $this->getExistingContent();
        $lastEpicNum = $this->findLastEpicNumber($existingContent);

        $epicNum = $lastEpicNum + 1;
        info("Creating Epic {$epicNum}");

        $epicTitle = text(
            label: 'Epic Title',
            placeholder: 'e.g., Organization & User Management',
            required: 'Epic title is required',
            hint: 'Describe the major feature area this Epic covers'
        );

        $epicActors = $this->promptForEpicActors();
        note('Actors defined: '.implode(', ', $epicActors));

        $userStories = [];
        $storyNum = 1;

        do {
            note("--- User Story {$epicNum}.{$storyNum} ---");

            $story = $this->promptForUserStory($epicNum, $storyNum, $epicActors);
            $userStories[] = $story;
            $storyNum++;

            $addMore = confirm(
                label: 'Add another User Story to this Epic?',
                default: true
            );
        } while ($addMore);

        $markdown = $this->generateEpicMarkdown($epicNum, $epicTitle, $userStories);

        $this->appendToFile($markdown, $existingContent);

        outro("Epic {$epicNum} with ".count($userStories).' User Stories created successfully!');
        $this->line("File: {$this->filePath}");

        if (confirm('Would you like to validate the file now?', true)) {
            $this->call('stories:lint', ['module' => $this->argument('module')]);
        }

        return self::SUCCESS;
    }

    private function promptForEpicActors(): array
    {
        $existingActors = $this->parseExistingActors();
        $selectedActors = [];

        if (! empty($existingActors)) {
            $options = array_combine($existingActors, $existingActors);
            $options['__add_new__'] = '+ Add new actors...';

            $selected = multiselect(
                label: 'Select actors for this Epic',
                options: $options,
                hint: 'Space to select, Enter to confirm',
                required: true
            );

            $selectedActors = array_filter($selected, fn ($item) => $item !== '__add_new__');
            $wantsToAddNew = in_array('__add_new__', $selected);

            if ($wantsToAddNew) {
                $newActorsInput = text(
                    label: 'New actors (comma-separated)',
                    placeholder: 'e.g., Office on Duty, Host, Security Guard',
                    required: empty($selectedActors) ? 'At least one actor is required' : false
                );

                if (! empty($newActorsInput)) {
                    $newActors = array_map('trim', explode(',', $newActorsInput));
                    $newActors = array_filter($newActors);
                    $selectedActors = array_merge($selectedActors, $newActors);
                }
            }
        } else {
            $newActorsInput = text(
                label: 'Define actors for this Epic (comma-separated)',
                placeholder: 'e.g., Office on Duty, Host, Security Guard',
                required: 'At least one actor is required'
            );

            $selectedActors = array_map('trim', explode(',', $newActorsInput));
            $selectedActors = array_filter($selectedActors);
        }

        return array_unique($selectedActors);
    }

    private function createSingleUserStory(): int
    {
        $existingContent = $this->getExistingContent();
        $isNewEpic = false;
        $epicActors = [];

        if (empty($existingContent)) {
            warning('No existing User Stories file found. Creating new file with document header.');

            $epicNum = 1;
            $epicTitle = text(
                label: 'Epic Title (for new file)',
                placeholder: 'e.g., Organization & User Management',
                required: 'Epic title is required'
            );
            $storyNum = 1;
            $isNewEpic = true;
        } else {
            $epics = $this->parseExistingEpics($existingContent);

            if (empty($epics)) {
                warning('No Epics found in file. Creating Epic 1.');
                $epicNum = 1;
                $epicTitle = text(
                    label: 'Epic Title',
                    placeholder: 'e.g., Organization & User Management',
                    required: 'Epic title is required'
                );
                $storyNum = 1;
                $isNewEpic = true;
            } else {
                $epicOptions = [];
                foreach ($epics as $num => $title) {
                    $epicOptions[$num] = "Epic {$num}: {$title}";
                }
                $epicOptions['new'] = '+ Create new Epic';

                $selectedEpic = select(
                    label: 'Which Epic should this User Story belong to?',
                    options: $epicOptions
                );

                if ($selectedEpic === 'new') {
                    $epicNum = max(array_keys($epics)) + 1;
                    $epicTitle = text(
                        label: 'New Epic Title',
                        placeholder: 'e.g., Reporting & Analytics',
                        required: 'Epic title is required'
                    );
                    $storyNum = 1;
                    $isNewEpic = true;
                } else {
                    $epicNum = (int) $selectedEpic;
                    $epicTitle = $epics[$epicNum];
                    $storyNum = $this->findLastStoryInEpic($existingContent, $epicNum) + 1;
                    $isNewEpic = false;
                }
            }
        }

        if ($isNewEpic) {
            $epicActors = $this->promptForEpicActors();
            note('Actors defined: '.implode(', ', $epicActors));
        }

        info("Creating US-{$epicNum}.{$storyNum}");

        $story = $this->promptForUserStory($epicNum, $storyNum, $epicActors);

        if (empty($existingContent)) {
            $markdown = $this->generateDocumentHeader();
            $markdown .= $this->generateEpicMarkdown($epicNum, $epicTitle, [$story]);
        } elseif (isset($selectedEpic) && $selectedEpic === 'new') {
            $markdown = $this->generateEpicMarkdown($epicNum, $epicTitle, [$story]);
            $this->appendToFile($markdown, $existingContent);

            outro("US-{$epicNum}.{$storyNum} created in new Epic {$epicNum}!");
            $this->line("File: {$this->filePath}");

            return self::SUCCESS;
        } else {
            $markdown = $this->generateUserStoryMarkdown($story);
            $this->insertIntoEpic($existingContent, $epicNum, $markdown);

            outro("US-{$epicNum}.{$storyNum} created!");
            $this->line("File: {$this->filePath}");

            return self::SUCCESS;
        }

        File::put($this->filePath, $markdown);

        outro("US-{$epicNum}.{$storyNum} created!");
        $this->line("File: {$this->filePath}");

        if (confirm('Would you like to validate the file now?', true)) {
            $this->call('stories:lint', ['module' => $this->argument('module')]);
        }

        return self::SUCCESS;
    }

    private function promptForUserStory(int $epicNum, int $storyNum, array $epicActors = []): array
    {
        $title = text(
            label: "US-{$epicNum}.{$storyNum} Title",
            placeholder: 'e.g., Create Organization Unit',
            required: 'User Story title is required',
            hint: 'Brief description of what this story enables'
        );

        $actors = ! empty($epicActors)
            ? $epicActors
            : [$this->promptForActor()];

        $want = textarea(
            label: 'I want to... (Goal)',
            placeholder: 'e.g., create organization units with name, code, and type',
            required: 'Goal is required',
            hint: 'What does the user want to accomplish?'
        );

        $benefit = textarea(
            label: 'So that... (Benefit)',
            placeholder: 'e.g., I can define the organizational hierarchy',
            required: 'Benefit is required',
            hint: 'Why does this matter? What value does it provide?'
        );

        $acceptanceCriteria = [];
        $acNum = 1;

        do {
            $acDesc = text(
                label: "AC{$acNum} Description",
                placeholder: 'e.g., Can create unit with valid name and code',
                required: 'Acceptance Criteria description is required',
                hint: 'A specific, testable condition'
            );

            $acceptanceCriteria[] = $acDesc;
            $acNum++;

            $addMoreAC = $acNum <= 2
                ? true
                : confirm('Add another Acceptance Criteria?', $acNum <= 5);
        } while ($addMoreAC);

        $technicalNotes = [];
        if (confirm('Add Technical Notes?', false)) {
            $notesInput = textarea(
                label: 'Technical Notes',
                placeholder: "- Implementation note 1\n- Implementation note 2",
                hint: 'Enter each note on a new line, starting with -'
            );

            if (! empty($notesInput)) {
                $technicalNotes = array_filter(
                    array_map(
                        fn ($line) => trim(ltrim(trim($line), '-')),
                        explode("\n", $notesInput)
                    )
                );
            }
        }

        return [
            'epicNum' => $epicNum,
            'storyNum' => $storyNum,
            'title' => $title,
            'actors' => $actors,
            'want' => $want,
            'benefit' => $benefit,
            'acceptanceCriteria' => $acceptanceCriteria,
            'technicalNotes' => $technicalNotes,
        ];
    }

    private function generateDocumentHeader(): string
    {
        $header = "# {$this->moduleName} Module\n";
        $header .= "## User Stories Documentation\n\n";
        $header .= "---\n\n";

        return $header;
    }

    private function generateEpicMarkdown(int $epicNum, string $epicTitle, array $userStories): string
    {
        $markdown = "## Epic {$epicNum}: {$epicTitle}\n\n";

        foreach ($userStories as $index => $story) {
            $markdown .= $this->generateUserStoryMarkdown($story);

            if ($index < count($userStories) - 1) {
                $markdown .= "---\n\n";
            }
        }

        $markdown .= "---\n\n";

        return $markdown;
    }

    private function generateUserStoryMarkdown(array $story): string
    {
        $actorsText = is_array($story['actors'])
            ? implode(', ', $story['actors'])
            : $story['actors'];

        $md = "### US-{$story['epicNum']}.{$story['storyNum']}: {$story['title']}\n";
        $md .= "**As a** {$actorsText}  \n";
        $md .= "**I want to** {$story['want']}  \n";
        $md .= "**So that** {$story['benefit']}\n\n";

        $md .= "**Acceptance Criteria:**\n";
        foreach ($story['acceptanceCriteria'] as $index => $ac) {
            $acNum = $index + 1;
            $md .= "- AC{$acNum}: {$ac}\n";
        }
        $md .= "\n";

        if (! empty($story['technicalNotes'])) {
            $md .= "**Technical Notes:**\n";
            foreach ($story['technicalNotes'] as $note) {
                $md .= "- {$note}\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    private function getExistingContent(): string
    {
        if (File::exists($this->filePath)) {
            return File::get($this->filePath);
        }

        return '';
    }

    private function findLastEpicNumber(string $content): int
    {
        if (empty($content)) {
            return 0;
        }

        preg_match_all('/^##\s+Epic\s+(\d+):/mi', $content, $matches);

        if (empty($matches[1])) {
            return 0;
        }

        return (int) max($matches[1]);
    }

    private function parseExistingEpics(string $content): array
    {
        $epics = [];

        preg_match_all('/^##\s+Epic\s+(\d+):\s*(.*)$/mi', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $epics[(int) $match[1]] = trim($match[2]);
        }

        return $epics;
    }

    private function findLastStoryInEpic(string $content, int $epicNum): int
    {
        preg_match_all("/^###\s+US-{$epicNum}\\.(\\d+):/mi", $content, $matches);

        if (empty($matches[1])) {
            return 0;
        }

        return (int) max($matches[1]);
    }

    private function appendToFile(string $markdown, string $existingContent): void
    {
        if (empty($existingContent)) {
            $fullContent = $this->generateDocumentHeader().$markdown;
        } else {
            $existingContent = rtrim($existingContent);
            if (! str_ends_with($existingContent, '---')) {
                $existingContent .= "\n\n---\n";
            }
            $fullContent = $existingContent."\n\n".$markdown;
        }

        File::put($this->filePath, $fullContent);
    }

    private function insertIntoEpic(string $content, int $epicNum, string $newStoryMarkdown): void
    {
        $nextEpicPattern = '/^##\s+Epic\s+'.($epicNum + 1).':/mi';

        if (preg_match($nextEpicPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insertPosition = $matches[0][1];
            $newContent = substr($content, 0, $insertPosition);
            $newContent .= $newStoryMarkdown."\n---\n\n";
            $newContent .= substr($content, $insertPosition);
        } else {
            $content = rtrim($content);
            if (str_ends_with($content, '---')) {
                $content = rtrim(substr($content, 0, -3));
            }
            $newContent = $content."\n\n".$newStoryMarkdown."---\n";
        }

        File::put($this->filePath, $newContent);
    }

    private function promptForActor(): string
    {
        $existingActors = $this->parseExistingActors();

        if (empty($existingActors)) {
            return text(
                label: 'As a... (Actor)',
                placeholder: 'e.g., System Administrator',
                required: 'Actor is required',
                hint: 'Who is the user performing this action?'
            );
        }

        $options = array_combine($existingActors, $existingActors);
        $options['__other__'] = '+ Enter new Actor';

        $selected = select(
            label: 'As a... (Actor)',
            options: $options,
            hint: 'Select actor or add new one'
        );

        if ($selected === '__other__') {
            return text(
                label: 'New Actor',
                placeholder: 'e.g., System Administrator',
                required: 'Actor is required',
                hint: 'Who is the user performing this action?'
            );
        }

        return $selected;
    }

    private function parseExistingActors(): array
    {
        $content = $this->getExistingContent();

        if (empty($content)) {
            return [];
        }

        preg_match_all('/\*\*As a\*\*\s+(.+?)(?:\s{2,}|\n)/i', $content, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $actors = array_unique(array_map('trim', $matches[1]));
        sort($actors);

        return $actors;
    }
}
