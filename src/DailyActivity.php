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
     *
     * @return void 无返回值，注册活动记录的创建事件。
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
     * @return array<string, class-string|string> 属性名与类型转换规则的映射。
     */
    protected function casts(): array
    {
        return ['days' => BinaryDays::class, 'year' => 'integer'];
    }

    /**
     * 获取活动记录所属主体的多态关联。
     *
     * @return MorphTo<Model, $this> 活动记录所属主体的多态关联。
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 为指定主体标记指定日期的活动，省略日期时默认今天，通过缓存去重和比较后更新保留并发标记。
     * 首次设置目标日期对应位时返回 true，重复标记返回 false，连续五次更新冲突后抛出异常。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string|null  $date  目标日期，按应用时区解析；传入 null 或省略时使用今天。
     * @return bool 首次标记目标日期时为 true，重复标记时为 false。
     */
    public function recordFor(Model $subject, string $action, CarbonInterface|string|null $date = null): bool
    {
        $query = $this->queryFor($subject, $action)->useWritePdo();
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $date = $date === null ? $now : $this->activityDate($date);
        $model = $query->getModel();
        $connection = $model->getConnection();
        $key = config('daily-activity.cache_key', 'daily-activity').':'.$action.':'.hash('sha256', json_encode([
            $connection->getName(), $connection->getDatabaseName(), $model->getTable(),
            $model::class, $subject->getMorphClass(), $subject->getKey(),
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
     * 从今天向前按需读取年度位图，遇到未标记日期即停止，今天未标记时从昨天开始统计。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 当前连续活动天数，今天未标记时保留截至昨天的连续记录。
     */
    public function currentStreakFor(Model $subject, string $action): int
    {
        $query = $this->queryFor($subject, $action);
        $today = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $year = $today->year;
        $offset = $today->dayOfYear - 1;
        $run = 0;

        while ($year >= 1) {
            $activity = (clone $query)->where('year', $year)->first(['days']);
            $days = $activity?->days;

            for (; $offset >= 0; $offset--) {
                if ($days !== null && self::marked($days, $offset)) {
                    $run++;
                } elseif ($year !== $today->year || $offset !== $today->dayOfYear - 1) {
                    return $run;
                }
            }

            $year--;
            // 只扫描实际日历日期，忽略非闰年的预留位及位图末尾的填充位。
            $offset = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0 ? 365 : 364;
        }

        return $run;
    }

    /**
     * 获取指定主体和活动截至今天的历史最长连续天数，支持跨年计算。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 截至今天的历史最长连续活动天数，支持跨年计算。
     */
    public function longestStreakFor(Model $subject, string $action): int
    {
        $query = $this->queryFor($subject, $action);
        $today = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $rows = $query->whereBetween('year', [1, $today->year])->orderBy('year')->get(['year', 'days']);
        $lookup = self::byteStreaks();
        $run = $longest = 0;
        $previousYear = null;

        foreach ($rows as $row) {
            $year = $row->year;

            if ($previousYear !== $year - 1) {
                $run = 0;
            }
            $leap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
            $limit = $year === $today->year ? $today->dayOfYear : ($leap ? 366 : 365);
            $days = $row->days;
            $fullBytes = intdiv($limit, 8);

            for ($byte = 0; $byte < $fullBytes; $byte++) {
                [$prefix, $suffix, $within] = $lookup[ord($days[$byte])];
                $longest = max($longest, $run + $prefix, $within);
                $run = $prefix === 8 ? $run + 8 : $suffix;
            }

            // 最后不足一个字节的部分逐位处理，不将未来位、预留位或填充位计入连续记录。
            for ($offset = $fullBytes * 8; $offset < $limit; $offset++) {
                $run = self::marked($days, $offset) ? $run + 1 : 0;
                $longest = max($longest, $run);
            }
            $previousYear = $year;
        }

        return $longest;
    }

    /**
     * 一次查询相关年份的位图，按日期升序返回包含起止日期的每日活动状态。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string  $start  查询起始日期，包含当天，按应用时区解析。
     * @param  CarbonInterface|string  $end  查询结束日期，包含当天，不得早于起始日期。
     * @param  string  $format  返回数组中日期键的格式，默认为 Y-m-d。
     * @return array<string, bool> 按日期升序排列的每日活动状态，键为格式化日期，值表示是否已标记。
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
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  string  $format  返回数组中日期键的格式，默认为 Y-m-d。
     * @return array<string, bool> 本月每日活动状态，键为格式化日期，值表示是否已标记，包含未来日期。
     */
    public function currentMonthFor(Model $subject, string $action, string $format = 'Y-m-d'): array
    {
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));

        return $this->dateRangeFor($subject, $action, $now->startOfMonth(), $now->endOfMonth(), $format);
    }

    /**
     * 按应用时区返回本周一至周日的每日活动状态。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  string  $format  返回数组中日期键的格式，默认为 Y-m-d。
     * @return array<string, bool> 本周一至周日的每日活动状态，键为格式化日期，值表示是否已标记。
     */
    public function currentWeekFor(Model $subject, string $action, string $format = 'Y-m-d'): array
    {
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));

        return $this->dateRangeFor($subject, $action, $now->startOfWeek(CarbonInterface::MONDAY), $now->endOfWeek(CarbonInterface::SUNDAY), $format);
    }

    /**
     * 获取指定日期区间内的活动总天数，包含起止日期。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string  $start  查询起始日期，包含当天，按应用时区解析。
     * @param  CarbonInterface|string  $end  查询结束日期，包含当天，不得早于起始日期。
     * @return int 指定日期范围内已标记的总天数，包含起止日期。
     */
    public function daysInRangeFor(Model $subject, string $action, CarbonInterface|string $start, CarbonInterface|string $end): int
    {
        return count(array_filter($this->dateRangeFor($subject, $action, $start, $end)));
    }

    /**
     * 获取本月的活动总天数。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 本月已标记的总天数。
     */
    public function currentMonthDaysFor(Model $subject, string $action): int
    {
        return count(array_filter($this->currentMonthFor($subject, $action)));
    }

    /**
     * 获取本周一至周日的活动总天数。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 本周一至周日已标记的总天数。
     */
    public function currentWeekDaysFor(Model $subject, string $action): int
    {
        return count(array_filter($this->currentWeekFor($subject, $action)));
    }

    /**
     * 获取指定日期区间内的最长连续活动天数，不计入区间外的记录。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string  $start  查询起始日期，包含当天，按应用时区解析。
     * @param  CarbonInterface|string  $end  查询结束日期，包含当天，不得早于起始日期。
     * @return int 指定日期范围内的最长连续活动天数，不计入区间外的记录。
     */
    public function longestStreakInRangeFor(Model $subject, string $action, CarbonInterface|string $start, CarbonInterface|string $end): int
    {
        return $this->longestStreakInDays($this->dateRangeFor($subject, $action, $start, $end));
    }

    /**
     * 获取本月内的最长连续活动天数。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 本月内的最长连续活动天数。
     */
    public function currentMonthLongestStreakFor(Model $subject, string $action): int
    {
        return $this->longestStreakInDays($this->currentMonthFor($subject, $action));
    }

    /**
     * 获取本周一至周日内的最长连续活动天数。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 本周一至周日内的最长连续活动天数。
     */
    public function currentWeekLongestStreakFor(Model $subject, string $action): int
    {
        return $this->longestStreakInDays($this->currentWeekFor($subject, $action));
    }

    /**
     * 计算每日活动状态中的最长连续天数。
     *
     * @param  array<string, bool>  $days  按日期升序排列的每日活动状态。
     * @return int 每日活动状态中的最长连续活动天数，无活动时为 0。
     */
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
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string  $date  目标日期，按应用时区解析。
     * @return bool 指定日期已标记时为 true，否则为 false。
     */
    public function hasActivityOnFor(Model $subject, string $action, CarbonInterface|string $date): bool
    {
        $date = $this->activityDate($date);

        return $this->dateRangeFor($subject, $action, $date, $date)[$date->toDateString()];
    }

    /**
     * 将日期转换为应用时区下当天零点的不可变对象，不修改传入对象。
     *
     * @param  CarbonInterface|string  $date  目标日期，按应用时区解析。
     * @return CarbonImmutable 应用时区下目标日期当天零点的不可变日期对象。
     */
    private function activityDate(CarbonInterface|string $date): CarbonImmutable
    {
        $timezone = config('app.timezone', 'UTC');

        return CarbonImmutable::parse($date, $timezone)->setTimezone($timezone)->startOfDay();
    }

    /**
     * 校验主体和活动标识，选择模型连接并构建限定主体与活动类型的查询。
     *
     * @param  Model  $subject  已持久化且具有主键的活动主体模型。
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return Builder<covariant self> 已限定主体、活动类型和数据库连接的活动查询构造器。
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
     * 为全部 256 种字节值构建连续标记摘要，在当前进程内复用查表结果。
     *
     * @return array<int, array{int, int, int}> 以字节值为键，依次包含开头、末尾和内部最长连续标记数。
     */
    private static function byteStreaks(): array
    {
        static $lookup = [];

        if ($lookup !== []) {
            return $lookup;
        }

        for ($value = 0; $value < 256; $value++) {
            $prefix = $suffix = $longest = 0;
            for ($bit = 7; $bit >= 0; $bit--) {
                if (($value & (1 << $bit)) !== 0) {
                    $suffix++;
                    $longest = max($longest, $suffix);

                    if ($prefix === 7 - $bit) {
                        $prefix++;
                    }
                } else {
                    $suffix = 0;
                }
            }
            $lookup[$value] = [$prefix, $suffix, $longest];
        }

        return $lookup;
    }

    /**
     * 按高位优先的位图顺序，判断从零开始的日期偏移是否已标记。
     *
     * @param  string  $days  按高位优先顺序存储的 46 字节年度活动位图。
     * @param  int  $offset  从零开始的年内日期偏移，1 月 1 日为 0。
     * @return bool 指定日期偏移已标记时为 true，否则为 false。
     */
    private static function marked(string $days, int $offset): bool
    {
        return (ord($days[intdiv($offset, 8)]) & (0x80 >> ($offset % 8))) !== 0;
    }

    /**
     * 在最外层事务提交后缓存成功标记，无事务时立即写入，有效期至本次操作当天结束。
     *
     * @param  self  $model  用于获取数据库连接及事务状态的活动模型。
     * @param  string  $key  包含前缀、活动类型和哈希的完整缓存键。
     * @param  CarbonImmutable  $now  本次操作的时间，用于确定缓存过期时间，避免跨日提交改变有效期。
     * @return void 无返回值，在事务提交后或无事务时写入成功缓存。
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
