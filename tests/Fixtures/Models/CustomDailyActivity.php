<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity\Tests\Fixtures\Models;

use VergilLai\DailyActivity\DailyActivity;

class CustomDailyActivity extends DailyActivity
{
    protected $table = 'custom_activities';
}
