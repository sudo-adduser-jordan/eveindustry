<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\StructureVulnerabilityWindow;
use App\Models\User;
use App\Services\EsiService;
use App\Services\IndustryService;
use App\Services\Notifications\Detectors\IndustryJobCompletedDetector;
use App\Services\Notifications\Detectors\PiExtractorExpiringDetector;
use App\Services\Notifications\Detectors\StructureWindowDetector;
use App\Services\PlanetaryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NotificationDetectorsTest extends TestCase
{
    use RefreshDatabase;

    // A Monday.
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-10-26 12:00:00', 'UTC');
    }

    private function user(?int $corporationId = null, array $scopes = []): User
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'pilot@test.com', 'is_active' => true, 'last_login_at' => $this->now]);
        Character::create([
            'character_id' => 9001, 'user_id' => $user->id, 'name' => 'Alpha', 'corporation_id' => $corporationId ?? 0,
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => $this->now->addHour(),
            'is_esi_active' => true, 'scopes' => $scopes,
        ]);

        return $user;
    }

    private function setting(?int $lead = 60, array $characters = []): array
    {
        return ['enabled' => true, 'lead_minutes' => $lead, 'character_ids' => $characters];
    }

    public function test_job_completed_builds_one_message_per_finished_job_and_honours_the_character_filter(): void
    {
        $user = $this->user();
        $industry = Mockery::mock(IndustryService::class);
        $industry->shouldReceive('getFinishedJobsForUser')->andReturn([
            ['job_id' => 11, 'character_id' => 9001, 'character_name' => 'Alpha', 'activity_id' => 1, 'activity_name' => 'Manufacturing', 'item_name' => 'Rifter', 'end_date' => '2026-10-26T11:58:00+00:00'],
            ['job_id' => 12, 'character_id' => 9002, 'character_name' => 'Beta', 'activity_id' => 9, 'activity_name' => 'Reactions', 'item_name' => 'Fullerides', 'end_date' => '2026-10-26T11:59:00+00:00'],
        ]);
        $detector = new IndustryJobCompletedDetector($industry);

        $all = $detector->detect($user, $this->setting(null), $this->now, $this->now->subHour());
        $this->assertSame(['industry:11:done', 'industry:12:done'], array_map(fn ($m) => $m->dedupeKey, $all));
        $this->assertStringContainsString('Rifter', $all[0]->body);

        $filtered = $detector->detect($user, $this->setting(null, [9002]), $this->now, $this->now->subHour());
        $this->assertSame(['industry:12:done'], array_map(fn ($m) => $m->dedupeKey, $filtered));
    }

    public function test_industry_service_returns_only_undelivered_jobs_that_ended_in_the_window(): void
    {
        $user = $this->user(null, ['esi-industry.read_character_jobs.v1', 'esi-skills.read_skills.v1']);
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterIndustryJobs')->andReturn([
            ['job_id' => 1, 'activity_id' => 1, 'status' => 'active', 'end_date' => '2026-10-26T11:30:00Z', 'product_type_id' => 587],
            ['job_id' => 2, 'activity_id' => 1, 'status' => 'active', 'end_date' => '2026-10-26T13:00:00Z', 'product_type_id' => 587], // still running
            ['job_id' => 3, 'activity_id' => 1, 'status' => 'delivered', 'end_date' => '2026-10-26T11:30:00Z', 'product_type_id' => 587],
            ['job_id' => 4, 'activity_id' => 9, 'status' => 'ready', 'end_date' => '2026-10-26T11:45:00Z', 'blueprint_type_id' => 1],
            ['job_id' => 5, 'activity_id' => 1, 'status' => 'active', 'end_date' => '2026-10-26T09:00:00Z', 'product_type_id' => 587], // before the window
        ]);
        $esi->shouldReceive('getTypeInfo')->with(587)->andReturn(['name' => 'Rifter']);
        $esi->shouldReceive('getTypeInfo')->with(1)->andReturn(null);

        $jobs = (new IndustryService($esi))->getFinishedJobsForUser($user->id, $this->now->subHour(), $this->now);

        $this->assertSame([1, 4], array_column($jobs, 'job_id'));
        $this->assertSame('Rifter', $jobs[0]['item_name']);
        $this->assertSame('Manufacturing', $jobs[0]['activity_name']);
        $this->assertSame('Reactions', $jobs[1]['activity_name']);
    }

    public function test_pi_groups_expiring_extractors_per_planet(): void
    {
        $user = $this->user(null, ['esi-planets.manage_planets.v1']);
        $planetary = Mockery::mock(PlanetaryService::class);
        $planetary->shouldReceive('getCharacterPlanetaryData')->andReturn(['planets' => [
            ['planet_id' => 1, 'planet_name' => 'Jita IV', 'pins' => [
                ['extractor' => ['expiry_time' => '2026-10-26T12:30:00Z']],
                ['extractor' => ['expiry_time' => '2026-10-26T12:45:00Z']],
                ['category' => 'storage'],
            ]],
            ['planet_id' => 2, 'planet_name' => 'Far away', 'pins' => [['extractor' => ['expiry_time' => '2026-10-27T12:00:00Z']]]],
            ['planet_id' => 3, 'planet_name' => 'Long gone', 'pins' => [['extractor' => ['expiry_time' => '2026-10-25T12:00:00Z']]]],
        ]]);

        $messages = (new PiExtractorExpiringDetector($planetary))->detect($user, $this->setting(60), $this->now, $this->now->subHour());

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('2 extractors on Jita IV', $messages[0]->body);
        $this->assertSame('pi:9001:1:'.CarbonImmutable::parse('2026-10-26 12:30:00', 'UTC')->getTimestamp(), $messages[0]->dedupeKey);
    }

    public function test_pi_reports_extractors_that_stopped_since_the_cutoff(): void
    {
        $user = $this->user(null, ['esi-planets.manage_planets.v1']);
        $planetary = Mockery::mock(PlanetaryService::class);
        $planetary->shouldReceive('getCharacterPlanetaryData')->andReturn(['planets' => [
            ['planet_id' => 1, 'planet_name' => 'Jita IV', 'pins' => [['extractor' => ['expiry_time' => '2026-10-26T11:40:00Z']]]],
        ]]);

        $messages = (new PiExtractorExpiringDetector($planetary))->detect($user, $this->setting(15), $this->now, $this->now->subHour());

        $this->assertStringContainsString('have stopped', $messages[0]->body);
    }

    public function test_structure_window_opening_uses_the_next_weekly_occurrence(): void
    {
        $user = $this->user(98000);
        // Monday 12:30 UTC: opens 30 minutes from the Monday 12:00 "now".
        StructureVulnerabilityWindow::create(['corporation_id' => 98000, 'solar_system_id' => 1, 'solar_system_name' => 'Jita', 'structure_type' => 'upwell', 'structure_name' => 'Fortizar', 'day_of_week' => 1, 'start_time_utc' => '12:30:00', 'duration_minutes' => 120]);
        // Wednesday: outside the lead time.
        StructureVulnerabilityWindow::create(['corporation_id' => 98000, 'solar_system_id' => 1, 'solar_system_name' => 'Jita', 'structure_type' => 'upwell', 'structure_name' => 'Astrahus', 'day_of_week' => 3, 'start_time_utc' => '12:30:00', 'duration_minutes' => 120]);
        // Another corporation's window.
        StructureVulnerabilityWindow::create(['corporation_id' => 1, 'solar_system_id' => 1, 'solar_system_name' => 'Jita', 'structure_type' => 'upwell', 'structure_name' => 'Other', 'day_of_week' => 1, 'start_time_utc' => '12:30:00', 'duration_minutes' => 120]);

        $messages = StructureWindowDetector::windowOpening()->detect($user, $this->setting(60), $this->now, $this->now->subHour());

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Fortizar in Jita', $messages[0]->body);
        $this->assertStringContainsString('12:30 UTC', $messages[0]->body);
    }

    public function test_daily_skyhook_window_rolls_to_tomorrow_once_it_has_opened(): void
    {
        $user = $this->user(98000);
        StructureVulnerabilityWindow::create(['corporation_id' => 98000, 'solar_system_id' => 1, 'solar_system_name' => 'Jita', 'structure_type' => 'skyhook', 'structure_name' => 'Hook', 'day_of_week' => null, 'start_time_utc' => '11:30:00', 'duration_minutes' => 60]);

        $detector = StructureWindowDetector::windowOpening();

        $this->assertSame([], $detector->detect($user, $this->setting(60), $this->now, $this->now->subHour()));
        $overnight = $this->now->setTime(10, 45);
        $this->assertCount(1, $detector->detect($user, $this->setting(60), $overnight, $overnight->subHour()));
    }

    public function test_structure_timer_announces_reinforcement_ending_within_the_lead_time(): void
    {
        $user = $this->user(98000);
        StructureVulnerabilityWindow::create(['corporation_id' => 98000, 'solar_system_id' => 1, 'solar_system_name' => 'Jita', 'structure_type' => 'upwell', 'structure_name' => 'Fortizar', 'day_of_week' => 1, 'start_time_utc' => '01:00:00', 'duration_minutes' => 120, 'reinforce_exit_at' => '2026-10-26 12:40:00']);

        $messages = StructureWindowDetector::timer()->detect($user, $this->setting(60), $this->now, $this->now->subHour());

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('reinforcement ends at 12:40 UTC', $messages[0]->body);
        $this->assertSame([], StructureWindowDetector::timer()->detect($user, $this->setting(15), $this->now, $this->now->subHour()));
    }
}
