# Release Notes

## v1.0.0 - 2026-10-21

首次正式发布 Laravel Daily Activity。

- 使用 46 字节年度位图记录每日活动，支持 Eloquent 多态主体、按天去重、指定日期和补签。
- 提供活动日历、区间/本周/本月活动天数、当前连续天数与历史最长连续天数查询，支持应用时区、跨年及闰年。
- 支持自定义活动模型和缓存键前缀，包含事务提交后缓存与并发更新保护。
- 优化连续天数统计：当前连续记录按需读取年份，历史最长记录使用字节查表扫描，并提供性能对比脚本。
- 支持 PHP 8.3+、Laravel 12/13，以及 SQLite、MySQL、PostgreSQL。

安装：

```bash
composer require vergil-lai/laravel-daily-activity:^1.0
php artisan vendor:publish --tag="laravel-daily-activity"
php artisan migrate

```
## Unreleased
