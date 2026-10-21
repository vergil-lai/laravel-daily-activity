<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use VergilLai\DailyActivity\DailyActivity;
use VergilLai\DailyActivity\Tests\Fixtures\Models\Subject;

beforeEach(function () {
    $this->createActivityTables();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('matches a bit string reference for every byte and partial byte boundary', function (int $day) {
    CarbonImmutable::setTestNow(sprintf('2026-01-%02d 12:00:00', $day));
    $subject = Subject::create();
    $activity = DailyActivity::create([
        'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
        'action' => 'login', 'year' => 2026,
    ]);

    for ($byte = 0; $byte < 256; $byte++) {
        // 前后各有三个连续标记，检查跨字节合并及未来位的截断。
        $days = "\x07".chr($byte)."\xe0".str_repeat("\0", 43);
        $activity->update(['days' => $days]);
        $bits = substr('00000111'.str_pad(decbin($byte), 8, '0', STR_PAD_LEFT).'11100000', 0, $day);
        $runs = explode('0', $bits);
        $expected = max(array_map('strlen', $runs));
        expect($subject->longestActivityStreak('login'))->toBe($expected, 'byte '.$byte.', day '.$day);
    }
})->with([...range(9, 16), 24]);

it('matches an independent daily reference across years gaps and future dates', function () {
    $today = new DateTimeImmutable('2026-10-22');
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');

    for ($seed = 0; $seed < 16; $seed++) {
        $subject = Subject::create();
        $bits = '';
        $previousYear = null;
        foreach ([1999, 2000, 2001, 2003, 2024, 2026, 2027] as $year) {
            $days = substr(hash('sha512', $seed.':'.$year, true), 0, 46);
            DailyActivity::create([
                'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
                'action' => 'login', 'year' => $year, 'days' => $days,
            ]);

            if ($year > 2026) {
                continue;
            }
            $start = new DateTimeImmutable($year.'-01-01');
            $length = $year === 2026 ? (int) $today->format('z') + 1 : 365 + (int) $start->format('L');
            $yearBits = '';
            foreach (str_split($days) as $byte) {
                $yearBits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
            }

            if ($previousYear !== $year - 1) {
                $bits .= '0';
            }
            $bits .= substr($yearBits, 0, $length);
            $previousYear = $year;
        }
        $expected = max(array_map('strlen', explode('0', $bits)));
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect($subject->longestActivityStreak('login'))->toBe($expected);
        expect(DB::getQueryLog())->toHaveCount(1);
        DB::disableQueryLog();
    }
});

it('joins full years using calendar lengths and ignores reserved bits', function (int $year, int $expected) {
    CarbonImmutable::setTestNow(($year + 1).'-01-01 12:00:00');
    $subject = Subject::create();
    foreach ([$year - 1, $year] as $recordYear) {
        DailyActivity::create([
            'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
            'action' => 'login', 'year' => $recordYear, 'days' => str_repeat("\xff", 46),
        ]);
    }
    expect($subject->longestActivityStreak('login'))->toBe($expected);
})->with([
    'leap century' => [2000, 731],
    'non-leap century' => [2100, 730],
]);
