<?php

declare(strict_types=1);

namespace PrivacyCI;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PrivacyCI\Console\BaselineCommand;
use PrivacyCI\Console\CheckCommand;
use PrivacyCI\Console\DiscoverCommand;
use PrivacyCI\Console\ForgetCommand;
use PrivacyCI\Console\MakeHandlerCommand;
use PrivacyCI\Console\MakePolicyCommand;
use PrivacyCI\Console\ProcessDeletionsCommand;
use PrivacyCI\Console\VerifyCommand;
use PrivacyCI\Discovery\Discoverer;
use PrivacyCI\Lifecycle\Clock;
use PrivacyCI\Lifecycle\DeletionSchedule;
use PrivacyCI\Lifecycle\DeletionStore;
use PrivacyCI\Lifecycle\Laravel\CancelDeletionOnLogin;
use PrivacyCI\Lifecycle\Laravel\CarbonClock;
use PrivacyCI\Lifecycle\Laravel\EloquentDeletionStore;
use PrivacyCI\Lifecycle\LifecycleMode;
use PrivacyCI\Lifecycle\NullDeleter;
use PrivacyCI\Lifecycle\NullNotifier;
use PrivacyCI\Lifecycle\NullSuspender;
use PrivacyCI\Lifecycle\SubjectDeleter;
use PrivacyCI\Lifecycle\SubjectNotifier;
use PrivacyCI\Lifecycle\SubjectSuspender;
use PrivacyCI\Verification\Verifier;

final class PrivacyCIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/privacy.php', 'privacy');

        $this->app->singleton(Discoverer::class, static fn (): Discoverer => new Discoverer(
            minConfidence: (float) config('privacy.confidence.report', 0.25),
        ));
        $this->app->singleton(Clock::class, static fn (): Clock => new CarbonClock);

        $this->app->singleton(DeletionStore::class, fn (): DeletionStore => new EloquentDeletionStore(
            $this->app['db']->connection(config('privacy.lifecycle.connection')),
            (string) config('privacy.lifecycle.table', 'privacy_deletion_requests'),
        ));

        $this->app->singleton(DeletionSchedule::class, function (): DeletionSchedule {
            $mode = LifecycleMode::tryFrom((string) config('privacy.lifecycle.mode', 'hold'))
                ?? LifecycleMode::Hold;

            return new DeletionSchedule(
                $this->app->make(DeletionStore::class),
                $this->app->make(Clock::class),
                (int) config('privacy.lifecycle.grace_days', DeletionSchedule::DEFAULT_GRACE_DAYS),
                $mode,
                $mode->suspendsImmediately() ? $this->app->make(SubjectSuspender::class) : null,
                array_values(array_map(intval(...), (array) config('privacy.lifecycle.remind_days', []))),
            );
        });

        $this->app->bindIf(SubjectNotifier::class, function (): SubjectNotifier {
            $configured = config('privacy.lifecycle.notifier');

            return is_string($configured) && $configured !== ''
                ? $this->app->make($configured)
                : new NullNotifier;
        });

        $this->app->bindIf(SubjectSuspender::class, function (): SubjectSuspender {
            $configured = config('privacy.lifecycle.suspender');

            return is_string($configured) && $configured !== ''
                ? $this->app->make($configured)
                : new NullSuspender;
        });

        $this->app->singleton(Verifier::class, function (): Verifier {
            $probes = [];

            foreach ((array) config('privacy.verification.probes', []) as $class) {
                if (is_string($class) && $class !== '') {
                    $probes[] = $this->app->make($class);
                }
            }

            return new Verifier($probes);
        });

        // Bound to a deleter that throws rather than one that no-ops: an
        // application must not be able to report "erased" having done nothing.
        $this->app->bindIf(SubjectDeleter::class, function (): SubjectDeleter {
            $configured = config('privacy.lifecycle.deleter');

            return is_string($configured) && $configured !== ''
                ? $this->app->make($configured)
                : new NullDeleter;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'privacy');

        $this->registerRoutes();
        $this->registerLoginListener();
        $this->registerSchedule();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/privacy.php' => config_path('privacy.php'),
        ], 'privacy-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/privacy'),
        ], 'privacy-views');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'privacy-migrations');

        $this->commands([
            BaselineCommand::class,
            CheckCommand::class,
            DiscoverCommand::class,
            ForgetCommand::class,
            MakeHandlerCommand::class,
            MakePolicyCommand::class,
            ProcessDeletionsCommand::class,
            VerifyCommand::class,
        ]);
    }

    /**
     * The reactivation pages are only served when the app is actually using the
     * emailed-link flow, so a hold-mode install does not expose routes it will
     * never link to.
     */
    private function registerRoutes(): void
    {
        if (! config('privacy.reactivation.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => (string) config('privacy.reactivation.prefix', 'privacy/reactivate'),
            'middleware' => (array) config('privacy.reactivation.middleware', ['web']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/privacy.php');
        });
    }

    private function registerLoginListener(): void
    {
        if (! config('privacy.lifecycle.cancel_on_login', true)) {
            return;
        }

        // In suspend mode the schedule ignores login activity anyway, but there
        // is no point dispatching a listener that can never act.
        $mode = LifecycleMode::tryFrom((string) config('privacy.lifecycle.mode', 'hold'));

        if ($mode !== null && ! $mode->cancelsOnLogin()) {
            return;
        }

        $this->app->make(Dispatcher::class)->listen(
            \Illuminate\Auth\Events\Login::class,
            CancelDeletionOnLogin::class,
        );
    }

    private function registerSchedule(): void
    {
        $cron = config('privacy.lifecycle.schedule');

        if (! is_string($cron) || $cron === '') {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($cron): void {
            $schedule->command(ProcessDeletionsCommand::class)
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
