<?php

namespace App\Services\Demo;

/** Made-up pilot and corporation names. Syllables are combined at random, so nothing is taken from real characters. */
final class DemoNames
{
    private const FIRST = ['Kael', 'Ryn', 'Tavo', 'Mira', 'Dax', 'Selen', 'Orrin', 'Vexa', 'Joren', 'Alis', 'Brask', 'Nyla', 'Corvin', 'Ilsa', 'Thane', 'Zora', 'Marek', 'Eryn', 'Valo', 'Sunn', 'Pike', 'Lorn', 'Wren', 'Davos', 'Isko', 'Talia', 'Hex', 'Quill', 'Rowan', 'Soren'];

    private const LAST = ['Vandrel', 'Okoro', 'Halvane', 'Strand', 'Mirosh', 'Tallen', 'Brightwater', 'Kessing', 'Duvall', 'Orlane', 'Pryce', 'Ashgrove', 'Nakamura-Ray', 'Velkor', 'Harrow', 'Ibsen', 'Cade', 'Lindqvist', 'Sarn', 'Moreau', 'Falk', 'Treven', 'Yoru', 'Grayle', 'Dunmere', 'Castellan', 'Rook', 'Thessaly', 'Voss', 'Amari'];

    private const CORP_A = ['Nova', 'Ironclad', 'Silent', 'Gilded', 'Northern', 'Ember', 'Quantum', 'Obsidian', 'Lunar', 'Cobalt', 'Veridian', 'Apex', 'Harbor', 'Stellar', 'Tidal'];

    private const CORP_B = ['Forge', 'Freight', 'Works', 'Extraction', 'Logistics', 'Syndicate', 'Fabrication', 'Holdings', 'Mining Co', 'Industries', 'Reclamation', 'Dynamics'];

    private const ALLIANCE = ['Aurora', 'Meridian', 'Ashen Coast', 'Fallow Reach', 'Kestrel', 'Open Horizon'];

    public static function pilot(int $n): string
    {
        return self::FIRST[random_int(0, count(self::FIRST) - 1)].' '.self::LAST[random_int(0, count(self::LAST) - 1)];
    }

    public static function corporation(): string
    {
        return self::CORP_A[random_int(0, count(self::CORP_A) - 1)].' '.self::CORP_B[random_int(0, count(self::CORP_B) - 1)];
    }

    public static function alliance(): string
    {
        return self::ALLIANCE[random_int(0, count(self::ALLIANCE) - 1)].' Compact';
    }

    public static function ticker(string $name): string
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($name));

        return substr($letters, 0, 4) ?: 'DEMO';
    }
}
