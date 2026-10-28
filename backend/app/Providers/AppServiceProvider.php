<?php

namespace App\Providers;

use App\Support\WorkspaceTables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WorkspaceTables::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Builder::macro('workspacePaginate', function (int $perPage = 25, array $columns = ['*'], string $pageName = 'page', ?int $page = null) {
            return app(WorkspaceTables::class)->paginate($this, $perPage, $columns, $pageName, $page);
        });
        \Illuminate\Database\Query\Builder::macro('workspacePaginate', function (int $perPage = 25, array $columns = ['*'], string $pageName = 'page', ?int $page = null) {
            return app(WorkspaceTables::class)->paginate($this, $perPage, $columns, $pageName, $page);
        });
    }
}
