<?php

declare(strict_types=1);

namespace Scoutapm\Laravel\Providers;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Capsule\Manager;
use Laravel\Lumen\Application;
use Illuminate\Contracts\View\Engine;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Scoutapm\Agent;
use Scoutapm\Config;
use Scoutapm\Config\ConfigKey;
use Scoutapm\Laravel\Listeners\QueryExecutedListener;
use Scoutapm\Laravel\Queue\JobQueueListener;
use Scoutapm\Laravel\View\Engine\ScoutViewCompilerEngineDecorator;
use Scoutapm\Laravel\View\Engine\ScoutViewEngineDecorator;
use Scoutapm\Logger\FilteredLogLevelDecorator;
use Scoutapm\ScoutApmAgent;
use Throwable;
use function array_combine;
use function array_filter;
use function array_map;
use function array_merge;
use function config_path;

final class ScoutApmServiceProvider extends ServiceProvider
{
    private const CONFIG_SERVICE_KEY = ScoutApmAgent::class . '_config';
    private const CACHE_SERVICE_KEY  = ScoutApmAgent::class . '_cache';

    private const VIEW_ENGINES_TO_WRAP = ['file', 'php', 'blade', 'twig'];

    public const INSTRUMENT_LARAVEL_QUEUES = 'laravel_queues';

    private $resolveEngineResolverOnBoot = false;

    /** @throws BindingResolutionException */
    public function register() : void
    {
        $this->app->singleton(self::CONFIG_SERVICE_KEY, function () {
            $configRepo = $this->app->make(ConfigRepository::class);

            return Config::fromArray(array_merge(
                array_filter(array_combine(
                    ConfigKey::allConfigurationKeys(),
                    array_map(
                    /** @return mixed */
                        static function (string $configurationKey) use ($configRepo) {
                            return $configRepo->get('scout_apm.' . $configurationKey);
                        },
                        ConfigKey::allConfigurationKeys()
                    )
                )),
                [
                    ConfigKey::FRAMEWORK => 'Laravel',
                    ConfigKey::FRAMEWORK_VERSION => $this->app->version(),
                ]
            ));
        });

        $this->app->singleton(self::CACHE_SERVICE_KEY, static function (Application $app) {
            try {
                $cacheManager = $app->make(CacheManager::class)->store();
                return $cacheManager instanceof Psr\SimpleCache\CacheInterface ? $cacheManager : null;
            } catch (Throwable $anything) {
                return null;
            }
        });

        $this->app->singleton(FilteredLogLevelDecorator::class, static function (Application $app) {
            return new FilteredLogLevelDecorator(
                $app->make('log'),
                $app->make(self::CONFIG_SERVICE_KEY)->get(ConfigKey::LOG_LEVEL)
            );
        });

        $this->app->singleton(ScoutApmAgent::class, static function (Application $app) {
            return Agent::fromConfig(
                $app->make(self::CONFIG_SERVICE_KEY),
                $app->make(FilteredLogLevelDecorator::class),
                $app->make(self::CACHE_SERVICE_KEY)
            );
        });

        /**
         * Depending on the laravel version and what other services are loaded, sometimes this is already resolved
         * If this is the case, then there isn't much point setting this, but we can handle it in the boot
         */
        if (!$this->app->resolved('view.engine.resolver')) {
            $this->app->afterResolving('view.engine.resolver', function (EngineResolver $engineResolver): void {
                $this->resolveEngines($engineResolver);
            });
        } else {
            $this->resolveEngineResolverOnBoot = true;
        }
    }

    /**
     * Going forward, we could use Union Types to hint this
     * @param Engine|CompilerEngine $realEngine
     *
     * @return Engine|CompilerEngine
     */
    public function wrapEngine($realEngine)
    {
        /** @var ViewFactory $viewFactory */
        $viewFactory = $this->app->make('view');

        if (class_exists(Engine::class)) {
            /** @noinspection UnusedFunctionResultInspection */
            $viewFactory->composer('*', static function (View $view) use ($viewFactory) : void {
                $viewFactory->share(ScoutViewEngineDecorator::VIEW_FACTORY_SHARED_KEY, $view->name());
            });

            return new ScoutViewEngineDecorator(
                $realEngine,
                $this->app->make(ScoutApmAgent::class),
                $viewFactory
            );
        }
        $viewFactory->composer('*', static function (View $view) use ($viewFactory) : void {
            $viewFactory->share(ScoutViewCompilerEngineDecorator::VIEW_FACTORY_SHARED_KEY, $view->name());
        });

        return new ScoutViewCompilerEngineDecorator(
            $realEngine,
            $this->app->make(ScoutApmAgent::class),
            $viewFactory
        );
    }

    /** @throws BindingResolutionException */
    public function boot(
        Application $application,
        ScoutApmAgent $agent,
        FilteredLogLevelDecorator $log
    ) : void {
        //For some reason, typehinting the connection seems to suck
        $connection = app('db')->connection();

        $runningInConsole = $application->runningInConsole();
        $this->instrumentDatabaseQueries($agent, $connection);

        if ($agent->shouldInstrument(self::INSTRUMENT_LARAVEL_QUEUES)) {
            $this->instrumentQueues($agent, $application->make('events'), $runningInConsole);
        }

        if ($runningInConsole) {
            return;
        }

        if ($this->resolveEngineResolverOnBoot) {
            $engineResolver = $this->app['view.engine.resolver'];
            $this->resolveEngines($engineResolver);

            $this->resolveEngineResolverOnBoot = false;
        }
    }

    private function resolveEngines(EngineResolver $engineResolver)
    {
        foreach (self::VIEW_ENGINES_TO_WRAP as $engineName) {
            try {
                $realEngine = $engineResolver->resolve($engineName);

                $engineResolver->register($engineName, function () use ($realEngine) {
                    return $this->wrapEngine($realEngine);
                });
            } catch (\InvalidArgumentException $anything) {
                /**
                 * Depending on the version of laravel, not every resolver exists
                 */
            }
        }
    }


    private function instrumentDatabaseQueries(ScoutApmAgent $agent, Connection $connection) : void
    {
        /**
         * @var Dispatcher $events
         */
        $events = app('events');
        $events->listen(QueryExecuted::class, QueryExecutedListener::class);
    }

    private function instrumentQueues(ScoutApmAgent $agent, Dispatcher $eventDispatcher, bool $runningInConsole) : void
    {
        $listener = new JobQueueListener($agent);

        $eventDispatcher->listen(JobProcessing::class, static function (JobProcessing $event) use ($listener, $runningInConsole) : void {
            if ($runningInConsole) {
                $listener->startNewRequestForJob();
            }

            $listener->startSpanForJob($event);
        });

        $eventDispatcher->listen(JobProcessed::class, static function (JobProcessed $event) use ($listener, $runningInConsole) : void {
            $listener->stopSpanForJob();

            if (! $runningInConsole) {
                return;
            }

            $listener->sendRequestForJob();
        });
    }
}
