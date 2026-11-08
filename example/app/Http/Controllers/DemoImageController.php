<?php

namespace App\Http\Controllers;

use App\Services\Demo\DemoIds;
use Illuminate\Http\Response;

/** Generated placeholder art for demo characters, corporations and alliances. */
class DemoImageController extends Controller
{
    public function portrait(int $id): Response
    {
        abort_unless(DemoIds::isDemo($id), 404);

        [$h1, $h2] = $this->hues($id);
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" role="img">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
<stop offset="0" stop-color="hsl({$h1} 55% 38%)"/><stop offset="1" stop-color="hsl({$h2} 60% 18%)"/></linearGradient></defs>
<rect width="128" height="128" fill="url(#g)"/>
<circle cx="64" cy="50" r="22" fill="hsl({$h1} 30% 78%)" opacity=".9"/>
<path d="M18 128c0-30 20-46 46-46s46 16 46 46z" fill="hsl({$h1} 30% 78%)" opacity=".9"/>
</svg>
SVG;

        return $this->svg($svg);
    }

    public function logo(int $id): Response
    {
        abort_unless(DemoIds::isDemo($id), 404);

        [$h1, $h2] = $this->hues($id);
        $sides = 3 + $id % 4;
        $points = [];
        for ($i = 0; $i < $sides; $i++) {
            $a = (2 * M_PI * $i / $sides) - M_PI / 2;
            $points[] = round(64 + 40 * cos($a), 1).','.round(64 + 40 * sin($a), 1);
        }
        $poly = implode(' ', $points);
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" role="img">
<rect width="128" height="128" rx="12" fill="hsl({$h2} 55% 14%)"/>
<polygon points="{$poly}" fill="hsl({$h1} 60% 50%)"/>
<circle cx="64" cy="64" r="12" fill="hsl({$h2} 55% 14%)"/>
</svg>
SVG;

        return $this->svg($svg);
    }

    /** @return array{int, int} */
    private function hues(int $id): array
    {
        $hash = crc32((string) $id);

        return [$hash % 360, ($hash >> 9) % 360];
    }

    private function svg(string $svg): Response
    {
        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
