<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UuidSubject extends Subject
{
    use HasUuids;

    protected $table = 'uuid_subjects';
}
