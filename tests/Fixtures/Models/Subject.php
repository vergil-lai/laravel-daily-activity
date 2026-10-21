<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use VergilLai\DailyActivity\Concerns\HasDailyActivities;

class Subject extends Model
{
    use HasDailyActivities;

    public $timestamps = false;

    protected $guarded = [];
}
