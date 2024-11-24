<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use App\Http\Middleware\CheckAuthUser;

class BitesServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register any bindings or other necessary services here.
    }

    public function boot()
    {
        $this->registerMigration();
        $this->publishAuth();
        $this->publishes(
            [
                __DIR__ . '/../Config/bites.php' => config_path('bites.php'),
            ],'bites'
        );
    }

    private function registerMigration()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }

    private function publishAuth()
    {
        $packagePath = 'Middleware/CheckAuthUser.stub';
        $targetPath = app_path('Http/Middleware/CheckAuthUser.php');
        if (File::exists($targetPath)) {
            return;
        }
        $stubPath = __DIR__ . '/../' . $packagePath;
        $namespace = app()->getNamespace();

        $stubContent = File::get($stubPath);
        $stubContent = str_replace('DummyNamespace', trim($namespace, '\\'), $stubContent);
        File::put($targetPath, $stubContent);
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddleware(CheckAuthUser::class);
    }

    private function syncMorph($relationFunction,$data){
        // Get the current related IDs
        $existing = $this->{$relationFunction}()->pluck('id')->toArray();

        // Extract the IDs from the new data
        $newIds = array_filter(array_column($data, 'id'));

        // Find to delete
        $toDelete = array_diff($existing, $newIds);
        $this->{$relationFunction}()->whereIn('id', $toDelete)->delete();

        foreach ($data as $comment) {
            if (isset($comment['id']) && in_array($comment['id'], $existing)) {
                // Update existing
                $this->{$relationFunction}()->where('id', $comment['id'])->update($comment);
            } else {
                // Create new
                $this->{$relationFunction}()->create($comment);
            }
        }
    }

}
