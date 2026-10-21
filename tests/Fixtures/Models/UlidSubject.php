<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

class UlidSubject extends Subject
{
    use HasUlids;

    protected $table = 'ulid_subjects';
}
