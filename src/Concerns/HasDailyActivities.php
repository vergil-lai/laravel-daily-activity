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
     * @return MorphMany<DailyActivity, $this>
     */
    public function dailyActivities(): MorphMany
    {
        return $this->morphMany($this->dailyActivityModel()::class, 'subject');
    }

    /**
     * 标记当前主体指定日期的活动，省略日期时默认今天，首次标记返回 true，重复标记返回 false。
     */
    public function recordDailyActivity(string $action, CarbonInterface|string|null $date = null): bool
    {
        return $this->dailyActivityModel()->recordFor($this, $action, $date);
    }

    /**
     * 获取当前主体指定活动的当前连续天数，允许今天尚未标记。
     */
    public function currentActivityStreak(string $action): int
    {
        return $this->dailyActivityModel()->currentStreakFor($this, $action);
    }

    /**
     * 获取当前主体指定活动截至今天的历史最长连续天数。
     */
    public function longestActivityStreak(string $action): int
    {
        return $this->dailyActivityModel()->longestStreakFor($this, $action);
    }

    /**
     * 查询当前主体在指定日期范围内每天的活动状态，包含起止日期。
     *
     * @return array<string, bool>
     */
    public function activityDateRange(string $action, CarbonInterface|string $start, CarbonInterface|string $end, string $format = 'Y-m-d'): array
    {
        return $this->dailyActivityModel()->dateRangeFor($this, $action, $start, $end, $format);
    }

    /**
     * 查询当前主体在当前整个月每天的活动状态，包含尚未到来的日期。
     *
     * @return array<string, bool>
     */
    public function currentMonthActivity(string $action, string $format = 'Y-m-d'): array
    {
        return $this->dailyActivityModel()->currentMonthFor($this, $action, $format);
    }

    /**
     * 查询当前主体本周一至周日的每日活动状态。
     *
     * @return array<string, bool>
     */
    public function currentWeekActivity(string $action, string $format = 'Y-m-d'): array
    {
        return $this->dailyActivityModel()->currentWeekFor($this, $action, $format);
    }

    /** 获取指定日期区间内的活动总天数，包含起止日期。 */
    public function activityDaysInRange(string $action, CarbonInterface|string $start, CarbonInterface|string $end): int
    {
        return $this->dailyActivityModel()->daysInRangeFor($this, $action, $start, $end);
    }

    /** 获取本月的活动总天数。 */
    public function currentMonthActivityDays(string $action): int
    {
        return $this->dailyActivityModel()->currentMonthDaysFor($this, $action);
    }

    /** 获取本周一至周日的活动总天数。 */
    public function currentWeekActivityDays(string $action): int
    {
        return $this->dailyActivityModel()->currentWeekDaysFor($this, $action);
    }

    /** 获取指定日期区间内的最长连续活动天数，不计入区间外的记录。 */
    public function longestActivityStreakInRange(string $action, CarbonInterface|string $start, CarbonInterface|string $end): int
    {
        return $this->dailyActivityModel()->longestStreakInRangeFor($this, $action, $start, $end);
    }

    /** 获取本月内的最长连续活动天数。 */
    public function currentMonthLongestActivityStreak(string $action): int
    {
        return $this->dailyActivityModel()->currentMonthLongestStreakFor($this, $action);
    }

    /** 获取本周一至周日内的最长连续活动天数。 */
    public function currentWeekLongestActivityStreak(string $action): int
    {
        return $this->dailyActivityModel()->currentWeekLongestStreakFor($this, $action);
    }

    /**
     * 判断当前主体在指定日期是否已标记对应活动。
     */
    public function hasActivityOn(string $action, CarbonInterface|string $date): bool
    {
        return $this->dailyActivityModel()->hasActivityOnFor($this, $action, $date);
    }

    /**
     * 校验配置的活动模型类型，并创建独立的模型实例。
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
