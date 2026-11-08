<?php

namespace App\Services\Esi;

use App\Models\Character;
use App\Services\RefreshEligibility;
use Illuminate\Support\Facades\Cache;

/**
 * Industry job ESI endpoints for characters and corporations.
 */
class IndustryClient
{
    public function __construct(
        protected EsiHttpClient $http,
    ) {}

    public function getCharacterIndustryJobs(Character $character, bool $includeCompleted = false): ?array
    {
        if (! RefreshEligibility::character($character, ['esi-industry.read_character_jobs.v1'])) {
            return null;
        }

        $cacheKey = "esi.industry_jobs.{$character->character_id}".($includeCompleted ? '.with_completed' : '.active');

        // Cache character's industry jobs for 5 minutes (300 seconds)
        return Cache::remember($cacheKey, 300, function () use ($character, $includeCompleted) {
            $endpoint = "/characters/{$character->character_id}/industry/jobs/";
            if ($includeCompleted) {
                $endpoint .= '?include_completed=true';
            }

            return $this->http->authenticatedResponse('get', $endpoint, $character)->data();
        });
    }

    /**
     * Get corporation industry jobs from ESI.
     * Requires scope: esi-industry.read_corporation_jobs.v1
     */
    public function getCorporationIndustryJobs(Character $character, int $corporationId, bool $includeCompleted = false): ?array
    {
        if (! RefreshEligibility::corporation($character, $corporationId, 'esi-industry.read_corporation_jobs.v1', ['Factory_Manager'])) {
            return null;
        }

        $cacheKey = "esi.corp_industry_jobs.{$corporationId}".($includeCompleted ? '.with_completed' : '.active');

        return Cache::remember($cacheKey, 300, function () use ($character, $corporationId, $includeCompleted) {
            $endpoint = "/corporations/{$corporationId}/industry/jobs/";
            if ($includeCompleted) {
                $endpoint .= '?include_completed=true';
            }

            try {
                return $this->http->authenticatedPaginatedRequest($endpoint, $character);
            } catch (EsiRequestException $e) {
                if (in_array($e->outcome, ['forbidden', 'missing_scope'], true)) {
                    RefreshEligibility::denyCorporation($character, $corporationId, 'esi-industry.read_corporation_jobs.v1', ['Factory_Manager']);
                }
                throw $e;
            }
        });
    }

    public function clearCorporationIndustryJobsCache(int $corporationId): void
    {
        Cache::forget("esi.corp_industry_jobs.{$corporationId}.active");
        Cache::forget("esi.corp_industry_jobs.{$corporationId}.with_completed");
    }
}
