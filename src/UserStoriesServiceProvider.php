<?php

declare(strict_types=1);

namespace Larasai\UserStories;

use Illuminate\Support\ServiceProvider;
use Larasai\UserStories\Commands\CreateUserStory;
use Larasai\UserStories\Commands\GenerateTestTodoFromStories;
use Larasai\UserStories\Commands\LintTestNames;
use Larasai\UserStories\Commands\LintUserStories;
use Larasai\UserStories\Commands\UpdateTodoStatus;

class UserStoriesServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                LintUserStories::class,
                CreateUserStory::class,
                GenerateTestTodoFromStories::class,
                UpdateTodoStatus::class,
                LintTestNames::class,
            ]);
        }
    }
}
