<div align="center">
    <h1>Laravel Daily Activity</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/vergil-lai/laravel-daily-activity"><img src="https://img.shields.io/packagist/v/vergil-lai/laravel-daily-activity.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/vergil-lai/laravel-daily-activity"><img src="https://img.shields.io/packagist/php-v/vergil-lai/laravel-daily-activity.svg?style=flat-square" alt="Packagist PHP 版本要求"></a>
    <a href="https://packagist.org/packages/vergil-lai/laravel-daily-activity"><img src="https://badge.laravel.cloud/badge/vergil-lai/laravel-daily-activity?style=flat" alt="支持的 Laravel 版本"></a>
    <a href="https://github.com/vergil-lai/laravel-daily-activity/actions"><img alt="GitHub 工作流状态（main）" src="https://img.shields.io/github/actions/workflow/status/vergil-lai/laravel-daily-activity/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/vergil-lai/laravel-daily-activity"><img src="https://img.shields.io/packagist/dt/vergil-lai/laravel-daily-activity.svg?style=flat-square" alt="总下载量"></a>
</p>

基于 **Bitmap（位图）** 的 Laravel 每日活动记录扩展包。**每天只用 1 bit，每个主体、每种活动一整年的活动状态仅需 46 字节位图**，支持签到、补签、活动日历和连续天数统计。

## 核心特性

- **按年压缩存储**：将全年的每日活动状态放入一个固定 46 字节的位图，每个主体、活动类型和年份只占一行记录，减少逐日记录带来的行与索引开销。
- **按天去重**：同一天的同一种活动只标记一次，首次标记返回 `true`，重复标记返回 `false`；当天访问多次也只计一个活动日。
- **活动日历与天数统计**：查询指定区间、本周或本月的每日状态、活动总天数和最长连续天数，也可统计当前连续天数及历史最长连续天数。
- **指定日期与补签**：默认记录今天，也可传入指定日期，补签后自动纳入活动天数与连续天数统计。
- **接入 Eloquent 模型**：通过 `HasDailyActivities` trait 接入用户等已持久化模型，以多态关联区分主体，以 `login`、`check-in` 等标识区分活动类型。
- **缓存与并发保护**：缓存减少重复标记的数据库访问，唯一约束和比较后更新保护并发写入；事务中的成功缓存延迟至最外层事务提交后写入。
- **跨数据库支持**：支持 MySQL、PostgreSQL 和 SQLite，按应用时区处理日历日期，并支持跨年和闰年统计。

## 为什么使用 Bitmap？

如果只需要知道某一天是否发生过活动，一个二进制位就足以表达：`1` 表示有活动，`0` 表示没有活动。例如，连续五天的状态 `11010` 表示第 1、2、4 天有活动，共 3 个活动日，最长连续 2 天。

一年最多 366 天，需要 366 bit，向上取整为 **46 字节**。活动次数增加时，只需更新当天对应的位；同一年度位图不会随着活动天数增加而变大。

| 对比项 | 每个活动日存一行 | 本扩展包的年度 Bitmap |
| --- | --- | --- |
| 同一主体、同一活动一年的记录行数 | 随活动天数增加，最多 365 / 366 行 | 有记录的年份仅需 1 行 |
| 每日状态的存储方式 | 每行保存主体、活动类型与日期等字段 | 每天 1 bit，全年位图固定 46 字节 |
| 主体与活动类型等信息 | 每个活动日重复保存 | 每年保存一次 |
| 活动日历查询 | 读取区间内的逐日记录并补齐空缺日期 | 读取相关年份位图并解析每日状态 |

例如，**100 万个主体各记录一种活动、每个主体都在同一年有活动**，位图字段的数据量合计为 `1,000,000 × 46 = 46,000,000` 字节，约 **43.9 MiB**。如果每个主体全年每天都活跃，按活动日逐行存储则需要 3.65 亿行记录（平年），年度 Bitmap 只需 100 万行。

上述 46 字节仅指 `days` 位图的原始数据，不包含主键、主体、活动类型、年份、索引和数据库自身的存储开销。实际节省比例取决于表结构与活动密度；对于每年仅有少量活动日的主体，固定长度位图未必更省空间。

## 适用场景

| 场景 | 使用方式 |
| --- | --- |
| 每日签到与补签 | 标记 `check-in`，展示本月签到日历、累计签到天数和连续签到天数 |
| 登录活跃记录 | 登录成功时标记 `login`，查询某个用户在指定区间内的活跃日期与天数 |
| 学习、阅读或运动打卡 | 分别标记 `study`、`read`、`workout`，独立统计各类习惯的活动日与连续记录 |
| 每日任务完成记录 | 用 `task:daily` 等活动标识记录当天是否完成，查询本周完成天数 |
| 活动日历与热力图 | 获取日期到布尔值的映射，展示“当天是否活跃”的日历或两级热力图 |

适合关注**“哪几天发生过”以及“持续了多少天”**的业务。若还需保存每次事件的时间、次数、内容、金额或审计轨迹，应另外保留事件明细；位图可作为按天状态的补充。积分发放、补签权限和次数限制等业务规则由应用自行处理。

## 安装

通过 Composer 安装：

```bash
composer require vergil-lai/laravel-daily-activity
```

可以一次性发布此扩展包的所有资源：

```bash
php artisan vendor:publish --tag="laravel-daily-activity"
```

也可以分别发布各项资源：

### 发布配置文件

```bash
php artisan vendor:publish --tag="laravel-daily-activity-config"
```

### 发布并执行数据库迁移

```bash
php artisan vendor:publish --tag="laravel-daily-activity-migrations"
php artisan migrate
```

## 使用方法

在任意已持久化的 Eloquent 模型中引入以下 trait：

```php
use Illuminate\Database\Eloquent\Model;
use VergilLai\DailyActivity\Concerns\HasDailyActivities;

class User extends Model
{
    use HasDailyActivities;
}

$user->recordDailyActivity('login'); // 今天首次标记时返回 true，否则返回 false
$user->recordDailyActivity('check-in'); // 独立的活动类型
$user->currentActivityStreak('login'); // int
$user->longestActivityStreak('login'); // int
$user->dailyActivities; // MorphMany，包含所有活动类型和年份
```

在登录事件、需要身份认证的接口或其他业务入口中调用 `recordDailyActivity()`。此扩展包不会注册应用路由或定时器。活动记录的 `subject()` 关联可解析出原始模型，并遵循 Laravel 的多态映射（morph map）。

活动类型（action）是区分大小写的机器标识符，长度为 1–64 个字符，仅允许小写 ASCII 字母、数字以及 `.`, `_`, `-`, `:`。无效的活动类型或尚未保存的主体模型会触发 `InvalidArgumentException`。此扩展包不会隐式保存主体模型，也不支持取消标记或统计重复事件次数。

### 指定日期与补签

`recordDailyActivity(string $action, Carbon\CarbonInterface|string|null $date = null): bool` 的日期参数可选，省略或传入 `null` 时记录今天。传入历史日期可用于补签：

```php
$user->recordDailyActivity('login'); // 记录今天
$user->recordDailyActivity('login', '2026-10-21'); // 补签指定日期
$user->recordDailyActivity('login', Carbon\CarbonImmutable::parse('2026-10-20'));
```

日期会转换到 `app.timezone` 后取对应的日历日期，传入的 Carbon 对象不会被修改。首次标记目标日期返回 `true`，重复标记返回 `false`；补签后，活动天数和连续天数统计会包含该日期。扩展包不限制可指定的日期范围或补签次数，相关业务规则由调用方控制。

活动模型对应方法为 `recordFor($subject, $action, $date = null)`，日期参数与 trait 方法一致。

### 查询活动日期

以下方法都通过 `$action` 指定要查询的活动类型，例如 `login`。每日状态映射的键默认为 `Y-m-d` 格式的日期，值为布尔值：`true` 表示当天有活动，`false` 表示当天没有活动。

```php
$user->activityDateRange('login', '2026-10-01', '2026-10-03');
// ['2026-10-01' => true, '2026-10-02' => false, '2026-10-03' => false]

$user->currentMonthActivity('login'); // 返回相同结构的映射，覆盖当前整个月
$user->currentWeekActivity('login'); // 本周一至周日的每日活动状态
$user->hasActivityOn('login', '2026-10-01'); // bool

$user->activityDateRange('login', $start, $end, format: 'd/m/Y');
$user->currentMonthActivity('login', format: 'd/m/Y');
$user->currentWeekActivity('login', format: 'd/m/Y');
```

查询范围包含起止日期，并按日期升序返回。没有记录的日期和年份返回 `false`。查询当前月份的方法会以当月第一天和最后一天为边界调用范围查询，结果包含尚未到来的日期。每次范围查询只执行一次数据库查询，获取涉及年份的位图。

日期参数支持字符串或 `Carbon\CarbonInterface` 实例（包括可变的 Carbon 和 CarbonImmutable）。输入会先转换到 `app.timezone` 时区，再忽略时分秒；传入的对象不会被修改。如果开始日期晚于结束日期，会抛出 `InvalidArgumentException`。请使用能唯一标识每一天的日期格式；重复的键会覆盖先前的条目，数字键则遵循 PHP 的数组键转换规则。

活动模型还提供对应的 `dateRangeFor($subject, $action, $start, $end, $format = 'Y-m-d')`、`currentMonthFor($subject, $action, $format = 'Y-m-d')` 和 `hasActivityOnFor($subject, $action, $date)` 方法。这些查询与已有统计方法一样，使用配置中指定的活动模型和数据库连接。

`currentWeekActivity()` 按应用时区查询本周，固定以周一开始、周日结束，包含尚未到来的日期，模型对应方法为 `currentWeekFor($subject, $action, $format = 'Y-m-d')`。

### 活动总天数与区间最长连续天数

以下六个统计方法均返回整数，连续活动时长以天为单位：

```php
$start = '2026-10-01';
$end = '2026-10-31';

$user->activityDaysInRange('login', $start, $end); // 区间内活动总天数，int
$user->currentMonthActivityDays('login'); // 本月活动总天数，int
$user->currentWeekActivityDays('login'); // 本周活动总天数，int

$user->longestActivityStreakInRange('login', $start, $end); // 区间内最长连续活动天数，int
$user->currentMonthLongestActivityStreak('login'); // 本月内最长连续活动天数，int
$user->currentWeekLongestActivityStreak('login'); // 本周内最长连续活动天数，int
```

以上统计复用每日活动状态查询，每次调用只查询一次数据库。总天数只统计已标记的日期，同一天的重复活动不重复计数。最长连续天数仅计算选定区间内的记录，区间外的连续活动不计入；未活动的日期或缺失的年份会中断连续记录。没有活动时返回 `0`，范围方法包含起止日期，并沿用上述日期类型、时区和范围校验规则。

例如，10 月只有 1 日、2 日、4 日有 `login` 活动，查询整个 10 月时，活动总天数为 `3`，最长连续活动天数为 `2`。如果只查询 10 月 2 日至 4 日，活动总天数为 `2`，最长连续活动天数为 `1`，因为 1 日不在所选区间内。

模型对应方法为 `daysInRangeFor($subject, $action, $start, $end)`、`currentMonthDaysFor($subject, $action)`、`longestStreakInRangeFor($subject, $action, $start, $end)` 和 `currentMonthLongestStreakFor($subject, $action)`。

本周统计使用周一至周日的范围，不计入上周的连续记录；模型对应方法为 `currentWeekDaysFor($subject, $action)` 和 `currentWeekLongestStreakFor($subject, $action)`。

### 配置与自定义模型

发布后的 `config/daily-activity.php` 包含以下配置：

```php
return [
    'model' => \VergilLai\DailyActivity\DailyActivity::class,
    'cache_key' => 'daily-activity',
];
```

如需自定义存储，请继承此扩展包的模型，并修改上述配置值：

```php
class Activity extends \VergilLai\DailyActivity\DailyActivity
{
    protected $table = 'custom_activities';
    // protected $connection = 'analytics';
}
```

关联、活动记录和统计均使用配置中指定的模型。活动模型显式指定的数据库连接优先；否则继承主体模型的连接。若反向 `subject()` 关联涉及不同数据库，主体模型必须显式声明自身的连接（例如 `protected $connection = 'primary';`）。否则，Eloquent 原生多态关联会继承活动模型的连接；仅在某个主体实例上设置的连接，无法通过已存储的多态类型和 ID 还原。活动模型本身还提供 `recordFor($subject, $action)`、`currentStreakFor($subject, $action)` 和 `longestStreakFor($subject, $action)` 方法。

### 使用整数、UUID 或 ULID 主键的主体模型

发布的迁移默认使用 `$table->morphs('subject')`。如果主体模型使用其他主键格式，请**在执行迁移之前**替换这一行：

```php
$table->uuidMorphs('subject'); // UUID 主键
// 或者
$table->ulidMorphs('subject'); // ULID 主键
```

存储在此表中的所有主体模型，其主键都必须与所选的 `subject_id` 列类型兼容。唯一键由 `subject_type`、`subject_id`、`action` 和 `year` 组成。如果使用自定义表或连接，请相应调整发布的迁移。此扩展包不会自动执行迁移；重新发布时若不使用 `--force`，将保留已发布的文件。请勿强制覆盖已经自定义或执行过的迁移文件。

### 日期与连续天数的计算规则

日期使用宿主应用的 `app.timezone` 时区（未配置时使用 UTC）。每次操作只获取一次当前日期。

- 如果今天已标记，当前连续天数计算至今天。
- 如果今天尚未标记，昨天结束的连续记录仍视为当前连续记录。
- 如果今天和昨天都未标记，当前连续天数为零。
- 最长连续天数可以跨年；缺少某一天或某一年的记录都会使连续记录中断。未来日期和未使用的位会被忽略。

例如，10 月 19 日和 20 日都有标记，则 10 月 21 日的当前连续天数为 2，10 月 22 日变为 0。最长连续天数仍为 2。没有记录时，连续天数查询返回 0。

当前连续天数从今天或昨天向前扫描，遇到未标记日期即停止。每次只查询一个年份的位图，仅当连续记录延伸至年初时才查询上一年，不加载无关的历史年份；跨年连续记录会产生多次年度查询。扫描使用整数日期偏移，不为每一天创建日期对象。

历史最长连续天数通过一次查询加载截至今年的年度位图，使用进程内复用的 256 项字节查表合并连续记录，最后不足一个字节的有效日期逐位处理。扫描不逐日创建日期对象，并忽略未来日期、预留位和填充位。可运行 `php tests/Support/benchmark-longest-streak.php` 对比优化前后的查询与扫描耗时；基准使用独立的 SQLite 内存数据库。

### 存储、缓存与事务

每个主体、活动类型和年份的组合占用一行记录，在 `days` 字段中存储一个 **46 字节的位图**：MySQL/SQLite 使用可移植的 BLOB 类型，PostgreSQL 使用 BYTEA。1 月 1 日对应第 0 个字节的最高位；前 366 位表示日历日期，剩余两位用于填充。平年仅使用其中的 365 个日期位。年份列使用 Laravel 的 `year()` 建表方法（MySQL 使用原生 YEAR 类型，PostgreSQL/SQLite 使用框架映射的类型）。新记录的位图字节均初始化为零；记录不包含时间戳。此二进制属性不会出现在数组或 JSON 输出中，但可以通过 `$activity->days` 访问。

宿主应用的默认缓存用于对目标日期成功的标记去重，有效期至本次操作当天结束（补签时也如此）。缓存键格式为 `{cache_key}:{action}:<哈希>`，例如 `daily-activity:login:<哈希>`。前缀通过 `daily-activity.cache_key` 配置，默认为 `daily-activity`；活动类型直接显示在键中，不参与哈希计算。哈希包含连接、数据库、表、模型、主体、目标日期和时区。缓存出错时会报告错误，并回退到数据库完成记录。使用相同存储标识的无关应用之间，请配置不同的 `cache_key` 或隔离缓存命名空间。

记录活动时通过写连接读取数据，冲突重试时也如此，以避免副本延迟。统计使用常规查询连接，并遵循宿主应用的读写一致性设置。即使没有缓存，数据库唯一约束和二进制比较后设置（compare-and-set）更新也能保留并发标记。比较失败时会重新加载数据并重试，最多尝试五次，之后抛出 `RuntimeException`，避免静默丢弃事件。批量记录会绕过逐行触发的 Eloquent 更新事件。此机制不需要长时间的内部事务或行锁支持。

如果调用方处于尚未提交的事务中，成功缓存仅在最外层事务提交后写入。事务回滚不会写入成功缓存。数据库错误会向上传播；发生死锁、SQLite busy/snapshot 错误或重试次数耗尽时，调用方必须重试**整个外层事务**。返回的布尔值表示本次调用是否设置了对应位，并不表示外层事务已经提交。

## 更新日志

近期变更详情请参阅[更新日志](CHANGELOG.md)。

## 许可证

[MIT](LICENSE.md)
