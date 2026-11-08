<?php

namespace App\Http\Controllers;

use App\Services\ReactionFormulaResearchService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReactionFormulaResearchController extends Controller
{
    public function __construct(private ReactionFormulaResearchService $service) {}

    public function export(Request $request): StreamedResponse
    {
        $search = $request->get('search');
        $groupId = $request->get('category') ? (int) $request->get('category') : null;
        $sortBy = $request->get('sort', 'name');
        $sortDir = $request->get('dir', 'asc');

        $data = $this->service->getReactionFormulaData(
            $request->user(),
            $search,
            $groupId,
            $sortBy,
            $sortDir,
        );

        $formulas = $data['formulas'];
        $hasBlueprintData = $data['hasBlueprintData'];

        $filename = 'reaction-formula-research-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($formulas, $hasBlueprintData) {
            $handle = fopen('php://output', 'w');

            $headers = ['Formula Name', 'Category', 'Owned'];
            if ($hasBlueprintData) {
                $headers[] = 'Quantity';
                $headers[] = 'Owners';
            }

            fputcsv($handle, $headers);

            foreach ($formulas as $formula) {
                $row = [
                    $formula->name,
                    $formula->category,
                    $formula->owned ? 'Yes' : 'No',
                ];

                if ($hasBlueprintData) {
                    $row[] = $formula->owned ? $formula->quantity : '';
                    $row[] = $formula->owned ? implode(', ', $formula->owner_names) : '';
                }

                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
