<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * 注册 Workbench 服务，当前无需额外注册。
     */
    public function register(): void
    {
        //
    }

    /**
     * 启动 Workbench 服务，当前无需额外初始化。
     */
    public function boot(): void
    {
        //
    }
}
