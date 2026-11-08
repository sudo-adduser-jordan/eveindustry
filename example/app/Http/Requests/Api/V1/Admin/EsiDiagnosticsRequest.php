<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\Esi\Diagnostics\EsiErrorClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * Time range and filters shared by every ESI diagnostics report.
 */
class EsiDiagnosticsRequest extends ApiRequest
{
    public const MAX_EVENTS_PER_PAGE = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Start of the range, ISO 8601, UTC unless an offset is given. Default 24 hours before `to`. */
            'from' => 'nullable|date',
            /** End of the range. Default now. The range may not exceed the configured maximum. */
            'to' => 'nullable|date',
            /** Normalized operation, for example `get /characters/{character_id}/assets`. */
            'operation' => 'nullable|string|max:160',
            /** Collector dataset label. */
            'dataset' => 'nullable|string|max:48',
            /** Actual HTTP status. */
            'status' => 'nullable|integer|min:100|max:599',
            /** Stable reason category. */
            'reason' => 'nullable|string|in:'.implode(',', EsiErrorClassifier::REASONS),
            /** Advertised rate-limit group. */
            'group' => 'nullable|string|max:64',
            /** Subject character (failure detail only). */
            'character_id' => 'nullable|integer|min:1',
            /** Target corporation (failure detail only). */
            'corporation_id' => 'nullable|integer|min:1',
            /** Cursor for failure detail: only rows with a lower id. Use `meta.next_cursor`. */
            'before_id' => 'nullable|integer|min:1',
            /** Failure detail page size, default 50, at most 100. */
            'per_page' => 'nullable|integer|min:1|max:'.self::MAX_EVENTS_PER_PAGE,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            [$from, $to] = $this->range();
            $max = (int) config('esi_diagnostics.max_range_days', 31);
            if ($from >= $to) {
                $validator->errors()->add('from', 'The start of the range must be before its end.');
            } elseif ($from->diffInSeconds($to) > $max * 86400) {
                $validator->errors()->add('from', "The range may not exceed {$max} days.");
            }
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $to = $this->filled('to') ? CarbonImmutable::parse($this->input('to'), 'UTC')->utc() : CarbonImmutable::now('UTC');
        $from = $this->filled('from') ? CarbonImmutable::parse($this->input('from'), 'UTC')->utc() : $to->subDay();

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter($this->validated(), fn ($v, $k) => ! in_array($k, ['from', 'to', 'before_id', 'per_page'], true) && $v !== null, ARRAY_FILTER_USE_BOTH);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 50);
    }
}
