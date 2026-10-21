<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;
use VergilLai\DailyActivity\DailyActivity;

/** @mixin Model */
trait HasDailyActivities
{
    /**
     * 获取当前主体所有活动类型和年份的活动记录关联。
     *
     * @return MorphMany<DailyActivity, $this> 当前主体所有活动记录的多态关联。
     */
    public function dailyActivities(): MorphMany
    {
        return $this->morphMany($this->dailyActivityModel()::class, 'subject');
    }

    /**
     * 标记当前主体指定日期的活动，省略日期时默认今天，首次标记返回 true，重复标记返回 false。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string|null  $date  目标日期，按应用时区解析；传入 null 或省略时使用今天。
     * @return bool 首次标记目标日期时为 true，重复标记时为 false。
     */
    public function recordDailyActivity(string $action, CarbonInterface|string|null $date = null): bool
    {
        return $this->dailyActivityModel()->recordFor($this, $action, $date);
    }

    /**
     * 获取当前主体指定活动的当前连续天数，允许今天尚未标记。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 当前连续活动天数，今天未标记时保留截至昨天的连续记录。
     */
    public function currentActivityStreak(string $action): int
    {
        return $this->dailyActivityModel()->currentStreakFor($this, $action);
    }

    /**
     * 获取当前主体指定活动截至今天的历史最长连续天数。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 截至今天的历史最长连续活动天数，支持跨年计算。
     */
    public function longestActivityStreak(string $action): int
    {
        return $this->dailyActivityModel()->longestStreakFor($this, $action);
    }

    /**
     * 查询当前主体在指定日期范围内每天的活动状态，包含起止日期。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string  $start  查询起始日期，包含当天，按应用时区解析。
     * @param  CarbonInterface|string  $end  查询结束日期，包含当天，不得早于起始日期。
     * @param  string  $format  返回数组中日期键的格式，默认为 Y-m-d。
     * @return array<string, bool> 按日期升序排列的每日活动状态，键为格式化日期，值表示是否已标记。
     */
    public function activityDateRange(string $action, CarbonInterface|string $start, CarbonInterface|string $end, string $format = 'Y-m-d'): array
    {
        return $this->dailyActivityModel()->dateRangeFor($this, $action, $start, $end, $format);
    }

    /**
     * 查询当前主体在当前整个月每天的活动状态，包含尚未到来的日期。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  string  $format  返回数组中日期键的格式，默认为 Y-m-d。
     * @return array<string, bool> 本月每日活动状态，键为格式化日期，值表示是否已标记，包含未来日期。
     */
    public function currentMonthActivity(string $action, string $format = 'Y-m-d'): array
    {
        return $this->dailyActivityModel()->currentMonthFor($this, $action, $format);
    }

    /**
     * 查询当前主体本周一至周日的每日活动状态。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  string  $format  返回数组中日期键的格式，默认为 Y-m-d。
     * @return array<string, bool> 本周一至周日的每日活动状态，键为格式化日期，值表示是否已标记。
     */
    public function currentWeekActivity(string $action, string $format = 'Y-m-d'): array
    {
        return $this->dailyActivityModel()->currentWeekFor($this, $action, $format);
    }

    /**
     * 获取指定日期区间内的活动总天数，包含起止日期。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string  $start  查询起始日期，包含当天，按应用时区解析。
     * @param  CarbonInterface|string  $end  查询结束日期，包含当天，不得早于起始日期。
     * @return int 指定日期范围内已标记的总天数，包含起止日期。
     */
    public function activityDaysInRange(string $action, CarbonInterface|string $start, CarbonInterface|string $end): int
    {
        return $this->dailyActivityModel()->daysInRangeFor($this, $action, $start, $end);
    }

    /**
     * 获取本月的活动总天数。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 本月已标记的总天数。
     */
    public function currentMonthActivityDays(string $action): int
    {
        return $this->dailyActivityModel()->currentMonthDaysFor($this, $action);
    }

    /**
     * 获取本周一至周日的活动总天数。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 本周一至周日已标记的总天数。
     */
    public function currentWeekActivityDays(string $action): int
    {
        return $this->dailyActivityModel()->currentWeekDaysFor($this, $action);
    }

    /**
     * 获取指定日期区间内的最长连续活动天数，不计入区间外的记录。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string  $start  查询起始日期，包含当天，按应用时区解析。
     * @param  CarbonInterface|string  $end  查询结束日期，包含当天，不得早于起始日期。
     * @return int 指定日期范围内的最长连续活动天数，不计入区间外的记录。
     */
    public function longestActivityStreakInRange(string $action, CarbonInterface|string $start, CarbonInterface|string $end): int
    {
        return $this->dailyActivityModel()->longestStreakInRangeFor($this, $action, $start, $end);
    }

    /**
     * 获取本月内的最长连续活动天数。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 本月内的最长连续活动天数。
     */
    public function currentMonthLongestActivityStreak(string $action): int
    {
        return $this->dailyActivityModel()->currentMonthLongestStreakFor($this, $action);
    }

    /**
     * 获取本周一至周日内的最长连续活动天数。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @return int 本周一至周日内的最长连续活动天数。
     */
    public function currentWeekLongestActivityStreak(string $action): int
    {
        return $this->dailyActivityModel()->currentWeekLongestStreakFor($this, $action);
    }

    /**
     * 判断当前主体在指定日期是否已标记对应活动。
     *
     * @param  string  $action  活动类型标识，长度为 1 至 64 个字符，仅允许小写英文字母、数字、点、下划线、冒号和连字符。
     * @param  CarbonInterface|string  $date  目标日期，按应用时区解析。
     * @return bool 指定日期已标记时为 true，否则为 false。
     */
    public function hasActivityOn(string $action, CarbonInterface|string $date): bool
    {
        return $this->dailyActivityModel()->hasActivityOnFor($this, $action, $date);
    }

    /**
     * 校验配置的活动模型类型，并创建独立的模型实例。
     *
     * @return DailyActivity 经过类型校验的独立活动模型实例。
     */
    private function dailyActivityModel(): DailyActivity
    {
        $class = config('daily-activity.model', DailyActivity::class);

        if (! is_string($class) || ! is_a($class, DailyActivity::class, true)) {
            throw new InvalidArgumentException('daily-activity.model must extend '.DailyActivity::class.'.');
        }

        return new $class;
    }
}
