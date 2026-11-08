<?php

namespace App\Http\Controllers;

use App\Services\BlueprintResearchService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BlueprintResearchController extends Controller
{
    public function __construct(private BlueprintResearchService $service) {}

    public function export(Request $request): StreamedResponse
    {
        $search = $request->get('search');
        $marketGroupId = $request->get('market_group') ? (int) $request->get('market_group') : null;
        $sortBy = $request->get('sort', 'name');
        $sortDir = $request->get('dir', 'asc');

        $data = $this->service->getBlueprintResearchData(
            $request->user(),
            $search,
            $marketGroupId,
            $sortBy,
            $sortDir,
        );

        $blueprints = $data['blueprints'];
        $hasBlueprintData = $data['hasBlueprintData'];

        $filename = 'blueprint-research-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($blueprints, $hasBlueprintData) {
            $handle = fopen('php://output', 'w');

            $headers = ['Blueprint Name', 'Owned'];
            if ($hasBlueprintData) {
                $headers[] = 'ME%';
                $headers[] = 'TE%';
                $headers[] = 'Owners';
            }
            $headers[] = 'Market Group';

            fputcsv($handle, $headers);

            foreach ($blueprints as $blueprint) {
                $row = [
                    $blueprint->name,
                    $blueprint->owned ? 'Yes' : 'No',
                ];

                if ($hasBlueprintData) {
                    $row[] = $blueprint->owned ? $blueprint->me : '';
                    $row[] = $blueprint->owned ? $blueprint->te : '';
                    $row[] = $blueprint->owned ? implode(', ', $blueprint->owner_names) : '';
                }

                $row[] = $blueprint->market_group_name ?? 'Unknown';

                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
