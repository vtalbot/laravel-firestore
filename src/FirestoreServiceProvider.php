<?php

namespace TalbotNinja\LaravelFirestore;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use TalbotNinja\LaravelFirestore\Auth\FirestoreUserProvider;
use TalbotNinja\LaravelFirestore\Cache\FirestoreStore;
use TalbotNinja\LaravelFirestore\Session\FirestoreSessionHandler;

class FirestoreServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/firestore.php' => config_path('firestore.php'),
        ]);

        $this->bootSession();
        $this->bootCache();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/firestore.php', 'firestore');

        $this->registerFirestoreClient();
        $this->registerUserProvider();
    }

    protected function registerFirestoreClient(): void
    {
        $this->app->bind(FirestoreClient::class, function () {
            return new FirestoreClient(array_filter([
                'projectId' => config('firestore.project_id'),
                'keyFilePath' => config('firestore.key_file_path'),
                'keyFile' => config('firestore.key_file'),
            ]));
        });
    }

    protected function registerUserProvider(): void
    {
        Auth::provider('firestore', static function ($app, array $config) {
            return new FirestoreUserProvider(
                $app->make(FirestoreClient::class),
                $config['model'],
                $config['table'],
                $config['password_field']
            );
        });
    }

    protected function bootCache(): void
    {
        Cache::extend('firestore', function (Container $app, array $config) {
            return Cache::repository(new FirestoreStore(
                $app->make(FirestoreClient::class),
                $config['table'],
                $config['prefix'] ?? $app['config']->get('cache.prefix')
            ));
        });
    }

    protected function bootSession(): void
    {
        Session::extend('firestore', static function (Container $app) {
            return new FirestoreSessionHandler(
                $app->make(FirestoreClient::class),
                $app['config']->get('session.table'),
                $app['config']->get('session.lifetime'),
                $app
            );
        });
    }
}
