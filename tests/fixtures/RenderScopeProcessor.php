<?php

namespace Tests\Fixtures;

use AdminService\Config;
use AdminService\ContextStorage;
use AdminService\ResponseProcessor\Http;

/**
 * 渲染作用域探针
 *
 * - 记录渲染阶段门面取到的配置值与当前请求上下文是否可见
 */
final class RenderScopeProcessor extends Http {

    /**
     * 渲染阶段读到的配置值
     * @var string|null
     */
    public static ?string $marker=null;

    /**
     * 渲染阶段是否存在请求上下文
     * @var bool
     */
    public static bool $has_context=false;

    /**
     * 处理响应数据
     *
     * @access public
     * @return void
     */
    public function handle(): void {
        self::$marker=(string)Config::get('probe.render','app');
        self::$has_context=ContextStorage::current()!==null;
        $this->getResponse()->rendered(self::$marker);
    }

}
