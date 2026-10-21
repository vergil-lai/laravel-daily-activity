<?php

declare(strict_types=1);

use VergilLai\DailyActivity\Casts\BinaryDays;
use VergilLai\DailyActivity\DailyActivity;

beforeEach(function () {
    $this->createActivityTables();
});

it('initializes a hidden 46 byte bitmap without timestamps', function () {
    $activity = DailyActivity::create(['subject_type' => 'user', 'subject_id' => 1, 'action' => 'login', 'year' => 2026]);
    expect($activity->fresh()->days)->toBe(str_repeat("\0", 46));
    expect($activity->toArray())->not->toHaveKey('days');
    expect($activity->toJson())->toBeString();
});

it('round trips binary bytes and compares them using a binary binding', function (string $bytes) {
    $activity = DailyActivity::create(['subject_type' => 'user', 'subject_id' => 1, 'action' => 'login', 'year' => 2026, 'days' => $bytes]);
    expect($activity->fresh()->days)->toBe($bytes);
    expect($activity->days)->toBe($bytes);
    $activity->year = 2025;
    $activity->save();
    expect($activity->fresh()->days)->toBe($bytes);
    expect(DailyActivity::whereKey($activity->id)->where('days', BinaryDays::binding($bytes))->count())->toBe(1);
})->with([
    'nul' => str_repeat("\0", 46),
    'high bit' => "\x80".str_repeat("\0", 45),
    'ff' => str_repeat("\xff", 46),
    'mixed' => str_repeat("\0\xff\\'", 11)."\x80\0",
]);

it('reads the same stream repeatedly without consuming it', function () {
    $cast = new BinaryDays;
    $stream = BinaryDays::binding(str_repeat("\xff", 46));
    for ($i = 0; $i < 2; $i++) {
        expect($cast->get(new DailyActivity, 'days', $stream, []))->toBe(str_repeat("\xff", 46));
    }
});

it('rejects invalid bitmap lengths', function (string $bytes) {
    expect(fn () => DailyActivity::create(['days' => $bytes]))->toThrow(InvalidArgumentException::class);
})->with(['', str_repeat("\0", 45), str_repeat("\0", 47)]);
