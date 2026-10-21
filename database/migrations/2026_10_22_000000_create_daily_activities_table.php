<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建每日活动表，以主体、活动类型和年份的组合唯一标识年度位图。
     */
    public function up(): void
    {
        Schema::create('daily_activities', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 64);
            $table->morphs('subject');
            $table->year('year');
            $table->binary('days');
            $table->unique(['subject_type', 'subject_id', 'action', 'year'], 'daily_activities_subject_action_year_unique');
        });
    }

    /**
     * 删除每日活动表，撤销本次迁移。
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_activities');
    }
};
