<?php

namespace Tests\Unit;

use Tests\TestCase;

class LoggingConfigTest extends TestCase
{
    public function test_daily_channel_keeps_five_days_by_default(): void
    {
        $this->assertSame('daily', config('logging.channels.daily.driver'));
        $this->assertSame(5, config('logging.channels.daily.days'));
    }

    public function test_default_stack_writes_to_the_daily_channel(): void
    {
        $this->assertSame(['daily'], config('logging.channels.stack.channels'));
    }
}
