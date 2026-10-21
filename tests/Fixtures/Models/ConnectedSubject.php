<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity\Tests\Fixtures\Models;

class ConnectedSubject extends Subject
{
    protected $connection = 'second';

    protected $table = 'subjects';
}
