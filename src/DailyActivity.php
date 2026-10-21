<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VergilLai\DailyActivity\Casts\BinaryDays;

/**
 * @property int $id
 * @property int $year
 * @property string $days
 */
class DailyActivity extends Model
{
    public $timestamps = false;

    protected $table = 'daily_activities';

    protected $fillable = ['subject_type', 'subject_id', 'action', 'year', 'days'];

    protected $hidden = ['days'];

    /**
     * 注册创建事件，为未指定 days 的新记录初始化全零的 46 字节位图。
     */
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            if (! array_key_exists('days', $activity->getAttributes())) {
                $activity->days = str_repeat("\0", 46);
            }
        });
    }

    /**
     * 定义位图的二进制转换器和年份的整数转换规则。
     *
     * @return array<string, class-string|string>
     */
    protected function casts(): array
    {
        return ['days' => BinaryDays::class, 'year' => 'integer'];
    }

    /**
     * 获取活动记录所属主体的多态关联。
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 为指定主体标记指定日期的活动，省略日期时默认今天，通过缓存去重和比较后更新保留并发标记。
     * 首次设置目标日期对应位时返回 true，重复标记返回 false，连续五次更新冲突后抛出异常。
     */
    public function recordFor(Model $subject, string $action, CarbonInterface|string|null $date = null): bool
    {
        $query = $this->queryFor($subject, $action)->useWritePdo();
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $date = $date === null ? $now : $this->activityDate($date);
        $model = $query->getModel();
        $connection = $model->getConnection();
        $key = 'daily-activity:'.hash('sha256', json_encode([
            $connection->getName(), $connection->getDatabaseName(), $model->getTable(),
            $model::class, $subject->getMorphClass(), $subject->getKey(), $action,
            $date->toDateString(), $date->getTimezone()->getName(),
        ], JSON_THROW_ON_ERROR));

        try {
            if (Cache::has($key)) {
                return false;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $attributes = [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'year' => $date->year,
        ];
        $activity = (clone $query)->firstOrCreate($attributes);
        $offset = $date->dayOfYear - 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $original = $activity->days;

            if (self::marked($original, $offset)) {
                $this->cacheAfterCommit($model, $key, $now);

                return false;
            }

            $updated = $original;
            $byte = intdiv($offset, 8);
            $updated[$byte] = chr((ord($updated[$byte]) | (0x80 >> ($offset % 8))) & 0xFF);
            $changed = (clone $query)->whereKey($activity->getKey())
                ->where('days', BinaryDays::binding($original))
                ->update(['days' => BinaryDays::binding($updated)]);

            if ($changed === 1) {
                $this->cacheAfterCommit($model, $key, $now);

                return true;
            }

            $activity = (clone $query)->where('year', $date->year)->firstOrFail();
        }

        throw new RuntimeException('Daily activity could not be recorded after 5 concurrent update attempts.');
    }

    /**
     * 获取指定主体和活动的当前连续天数，今天未标记时保留截至昨天的连续记录。
     */
    public function currentStreakFor(Model $subject, string $action): int
    {
        return $this->streaksFor($subject, $action)[0];
    }

    /**
     * 获取指定主体和活动截至今天的历史最长连续天数，支持跨年计算。
     */
    public function longestStreakFor(Model $subject, string $action): int
    {
        return $this->streaksFor($subject, $action)[1];
    }

    /**
     * 一次查询相关年份的位图，按日期升序返回包含起止日期的每日活动状态。
     *
     * @return array<string, bool>
     */
    public function dateRangeFor(Model $subject, string $action, CarbonInterface|string $start, CarbonInterface|string $end, string $format = 'Y-m-d'): array
    {
        $query = $this->queryFor($subject, $action);
        $start = $this->activityDate($start);
        $end = $this->activityDate($end);

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }

        $rows = $query->whereBetween('year', [$start->year, $end->year])->get(['year', 'days'])->keyBy('year');
        $result = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $row = $rows->get($date->year);
            $result[$date->format($format)] = $row !== null && self::marked($row->days, $date->dayOfYear - 1);
        }

        return $result;
    }

    /**
     * 按应用时区返回指定主体当前整个月的每日活动状态，包含未来日期。
     *
     * @return array<string, bool>
     */
    public function currentMonthFor(Model $subject, string $action, string $format = 'Y-m-d'): array
    {
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));

        return $this->dateRangeFor($subject, $action, $now->startOfMonth(), $now->endOfMonth(), $format);
    }

    /**
     * 按应用时区返回本周一至周日的每日活动状态。
     *
     * @return array<string, bool>
     */
    public function currentWeekFor(Model $subject, string $action, string $format = 'Y-m-d'): array
    {
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));

        return $this->dateRangeFor($subject, $action, $now->startOfWeek(CarbonInterface::MONDAY), $now->endOfWeek(CarbonInterface::SUNDAY), $format);
    }

    /** 获取指定日期区间内的活动总天数，包含起止日期。 */
    public function daysInRangeFor(Model $subject, string $action, CarbonInterface|string $start, CarbonInterface|string $end): int
    {
        return count(array_filter($this->dateRangeFor($subject, $action, $start, $end)));
    }

    /** 获取本月的活动总天数。 */
    public function currentMonthDaysFor(Model $subject, string $action): int
    {
        return count(array_filter($this->currentMonthFor($subject, $action)));
    }

    /** 获取本周一至周日的活动总天数。 */
    public function currentWeekDaysFor(Model $subject, string $action): int
    {
        return count(array_filter($this->currentWeekFor($subject, $action)));
    }

    /** 获取指定日期区间内的最长连续活动天数，不计入区间外的记录。 */
    public function longestStreakInRangeFor(Model $subject, string $action, CarbonInterface|string $start, CarbonInterface|string $end): int
    {
        return $this->longestStreakInDays($this->dateRangeFor($subject, $action, $start, $end));
    }

    /** 获取本月内的最长连续活动天数。 */
    public function currentMonthLongestStreakFor(Model $subject, string $action): int
    {
        return $this->longestStreakInDays($this->currentMonthFor($subject, $action));
    }

    /** 获取本周一至周日内的最长连续活动天数。 */
    public function currentWeekLongestStreakFor(Model $subject, string $action): int
    {
        return $this->longestStreakInDays($this->currentWeekFor($subject, $action));
    }

    /** @param array<string, bool> $days 按日期升序排列的每日活动状态。 */
    private function longestStreakInDays(array $days): int
    {
        $run = $longest = 0;

        foreach ($days as $active) {
            $run = $active ? $run + 1 : 0;
            $longest = max($longest, $run);
        }

        return $longest;
    }

    /**
     * 判断指定主体在应用时区下的指定日期是否已标记对应活动。
     */
    public function hasActivityOnFor(Model $subject, string $action, CarbonInterface|string $date): bool
    {
        $date = $this->activityDate($date);

        return $this->dateRangeFor($subject, $action, $date, $date)[$date->toDateString()];
    }

    /**
     * 将日期转换为应用时区下当天零点的不可变对象，不修改传入对象。
     */
    private function activityDate(CarbonInterface|string $date): CarbonImmutable
    {
        $timezone = config('app.timezone', 'UTC');

        return CarbonImmutable::parse($date, $timezone)->setTimezone($timezone)->startOfDay();
    }

    /**
     * 校验主体和活动标识，选择模型连接并构建限定主体与活动类型的查询。
     *
     * @return Builder<covariant self>
     */
    private function queryFor(Model $subject, string $action): Builder
    {
        if (! $subject->exists || $subject->getKey() === null) {
            throw new InvalidArgumentException('The activity subject must be persisted and have a primary key.');
        }

        if (! preg_match('/\A[a-z0-9._:-]{1,64}\z/', $action)) {
            throw new InvalidArgumentException('The action must be a 1-64 character lowercase ASCII machine identifier.');
        }

        $model = $this->newInstance();
        $model->setConnection($this->getConnectionName() ?? $subject->getConnectionName());

        return $model->newQuery()->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())->where('action', $action);
    }

    /**
     * 扫描截至今天的年度位图，返回当前连续天数和历史最长连续天数。
     *
     * @return array{int, int}
     */
    private function streaksFor(Model $subject, string $action): array
    {
        $query = $this->queryFor($subject, $action);
        $today = CarbonImmutable::now(config('app.timezone', 'UTC'))->startOfDay();
        $rows = $query->whereBetween('year', [1, $today->year])->orderBy('year')->get(['year', 'days']);
        $run = $longest = 0;
        $previousYear = null;
        $lastMarked = null;

        foreach ($rows as $row) {
            if ($previousYear !== $row->year - 1) {
                $run = 0;
            }
            $start = $today->setDate($row->year, 1, 1);
            $limit = $row->year === $today->year ? $today->dayOfYear : $start->daysInYear;
            $days = $row->days;
            for ($offset = 0; $offset < $limit; $offset++) {
                if (self::marked($days, $offset)) {
                    $run++;
                    $longest = max($longest, $run);
                    $lastMarked = $start->addDays($offset);
                } else {
                    // 今天尚未标记时，保留截至昨天的连续天数。
                    if ($row->year !== $today->year || $offset !== $today->dayOfYear - 1) {
                        $run = 0;
                    }
                }
            }
            $previousYear = $row->year;
        }

        $current = $lastMarked !== null && $lastMarked->greaterThanOrEqualTo($today->subDay()) ? $run : 0;

        return [$current, $longest];
    }

    /**
     * 按高位优先的位图顺序，判断从零开始的日期偏移是否已标记。
     */
    private static function marked(string $days, int $offset): bool
    {
        return (ord($days[intdiv($offset, 8)]) & (0x80 >> ($offset % 8))) !== 0;
    }

    /**
     * 在最外层事务提交后缓存成功标记，无事务时立即写入，有效期至本次操作当天结束。
     */
    private function cacheAfterCommit(self $model, string $key, CarbonImmutable $now): void
    {
        $publish = static function () use ($key, $now): void {
            try {
                Cache::put($key, true, $now->endOfDay());
            } catch (Throwable $exception) {
                report($exception);
            }
        };
        $connection = $model->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($publish);
        } else {
            $publish();
        }
    }
}
