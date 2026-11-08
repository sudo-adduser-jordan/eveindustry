<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureFeatureEnabled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class EnsureFeatureEnabledTest extends TestCase
{
    public function test_discord_bot_is_off_by_default(): void
    {
        $this->assertFalse(config('features.discord_bot'));
    }

    public function test_an_off_feature_is_a_404(): void
    {
        config()->set('features.discord_bot', false);

        $this->expectException(NotFoundHttpException::class);
        (new EnsureFeatureEnabled)->handle(Request::create('/'), fn () => new Response('ok'), 'discord_bot');
    }

    public function test_an_unknown_feature_is_a_404(): void
    {
        $this->expectException(NotFoundHttpException::class);
        (new EnsureFeatureEnabled)->handle(Request::create('/'), fn () => new Response('ok'), 'no_such_feature');
    }

    public function test_an_on_feature_passes_through(): void
    {
        config()->set('features.discord_bot', true);

        $response = (new EnsureFeatureEnabled)->handle(Request::create('/'), fn () => new Response('ok'), 'discord_bot');

        $this->assertSame('ok', $response->getContent());
    }
}
