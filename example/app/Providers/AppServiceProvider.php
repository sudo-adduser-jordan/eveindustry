<?php

namespace App\Providers;

use App\Services\Notifications\Detectors\IndustryJobCompletedDetector;
use App\Services\Notifications\Detectors\PiExtractorExpiringDetector;
use App\Services\Notifications\Detectors\StructureWindowDetector;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\PushSender;
use App\Services\Notifications\WebPushSender;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PushSender::class, WebPushSender::class);

        $this->app->bind(NotificationDispatcher::class, fn ($app) => new NotificationDispatcher(
            $app->make(PushSender::class),
            $app->make(NotificationPreferenceService::class),
            [
                $app->make(IndustryJobCompletedDetector::class),
                $app->make(PiExtractorExpiringDetector::class),
                StructureWindowDetector::windowOpening(),
                StructureWindowDetector::timer(),
            ],
        ));
    }

    public function boot(): void
    {
        \App\Models\Character::observe(\App\Observers\CharacterObserver::class);

        $providerClass = 'SocialiteProviders\\EveOnline\\Provider';

        JWT::$leeway = 120;

        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('eveonline', \SocialiteProviders\Eveonline\Provider::class);
        });
    }
}
