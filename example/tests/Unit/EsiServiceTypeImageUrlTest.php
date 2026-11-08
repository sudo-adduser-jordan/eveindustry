<?php

namespace Tests\Unit;

use App\Models\BlueprintActivity;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EsiServiceTypeImageUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        EsiService::forgetBlueprintTypeIds();
    }

    public function test_blueprint_types_use_the_bp_image_instead_of_icon(): void
    {
        BlueprintActivity::create(['blueprint_type_id' => 77402, 'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING]);
        EsiService::forgetBlueprintTypeIds();

        $this->assertSame('https://images.evetech.net/types/77402/bp?size=32', EsiService::getTypeImageUrl(77402));
        $this->assertSame('https://images.evetech.net/types/77402/bp?size=64', EsiService::getTypeImageUrl(77402, 'icon', 64));
    }

    public function test_other_types_keep_the_icon_image(): void
    {
        BlueprintActivity::create(['blueprint_type_id' => 77402, 'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING]);
        EsiService::forgetBlueprintTypeIds();

        $this->assertSame('https://images.evetech.net/types/34/icon?size=32', EsiService::getTypeImageUrl(34));
    }

    public function test_explicit_non_icon_styles_are_left_alone_for_blueprints(): void
    {
        BlueprintActivity::create(['blueprint_type_id' => 77402, 'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING]);
        EsiService::forgetBlueprintTypeIds();

        $this->assertSame('https://images.evetech.net/types/77402/bpc?size=32', EsiService::getTypeImageUrl(77402, 'bpc'));
        $this->assertSame('https://images.evetech.net/types/77402/render?size=32', EsiService::getTypeImageUrl(77402, 'render'));
    }
}
