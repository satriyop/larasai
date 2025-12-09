# Larasai User Stories

Laravel Artisan commands for managing User Stories, Acceptance Criteria, and test documentation in your Laravel projects.

## Installation

```bash
composer require larasai/user-stories --dev
```

The package will auto-register its service provider via Laravel's package discovery.

## Available Commands

### 1. Create User Stories (`stories:create`)

Interactively create User Stories following a strict schema format.

```bash
# Create a single User Story
php artisan stories:create attendance

# Create a complete Epic with multiple User Stories
php artisan stories:create attendance --epic
```

### 2. Lint User Stories (`stories:lint`)

Validate User Stories markdown files against the strict schema.

```bash
php artisan stories:lint attendance

# Show fix suggestions
php artisan stories:lint attendance --fix
```

### 3. Generate Test TODO (`test:generate-todo`)

Generate test TODO documentation from User Stories, mapping tests to Acceptance Criteria.

```bash
php artisan test:generate-todo attendance

# Run tests to get pass/fail status
php artisan test:generate-todo attendance --run-tests

# Preview without writing files
php artisan test:generate-todo attendance --dry-run

# Overwrite existing files
php artisan test:generate-todo attendance --force
```

### 4. Update TODO Status (`test:update-todo-status`)

Update test pass/fail status in existing TODO files without changing structure.

```bash
php artisan test:update-todo-status attendance

# Update only a specific Epic
php artisan test:update-todo-status attendance --epic=1
```

### 5. Lint Test Names (`test:lint-names`)

Validate test naming conventions against AC-tagging requirements.

```bash
# Lint all modules
php artisan test:lint-names

# Lint specific module
php artisan test:lint-names attendance

# Show fix suggestions
php artisan test:lint-names attendance --fix

# Fail on warnings (strict mode)
php artisan test:lint-names attendance --strict
```

## User Stories Schema

User Stories should follow this format:

```markdown
# Module Name

## Epic 1: Feature Area

### US-1.1: User Story Title
**As a** User Role
**I want to** do something
**So that** I can achieve a goal

**Acceptance Criteria:**
- AC1: First acceptance criteria
- AC2: Second acceptance criteria

---

### US-1.2: Another Story
...
```

## Test Naming Convention

Tests should be tagged with User Story and Acceptance Criteria references:

```php
it('[US-1.1][AC1] can create a new record', function () {
    // test implementation
});

it('[US-1.1][AC2] validates required fields', function () {
    // test implementation
});
```

## Directory Structure

The package expects this directory structure:

```
project/
├── {module}-todo/
│   ├── {PREFIX}_USER_STORIES.md
│   ├── {PREFIX}_TEST_TO_DO_EPIC_1.md
│   └── {PREFIX}_TEST_TO_DO_EPIC_2.md
└── tests/
    └── Feature/
        └── {Module}/
            ├── Epic-1/
            │   └── FeatureTest.php
            └── Epic-2/
                └── AnotherTest.php
```

## Configuration

No configuration is needed. The package auto-discovers:

- User Stories files from `{module}-todo/` directories
- Tests from `tests/Feature/{Module}/` directories
- Epic folders with `Epic-N` naming convention

## Local Development

To use this package in local development before publishing:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../larasai"
        }
    ],
    "require-dev": {
        "larasai/user-stories": "@dev"
    }
}
```

Then run:

```bash
composer require larasai/user-stories @dev --dev
```

## License

MIT License. See LICENSE for details.
