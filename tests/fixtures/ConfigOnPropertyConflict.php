<?php

namespace Tests\Fixtures;

use base\Attribute\Config;
use base\Attribute\AutowireProperty;
use base\Attribute\AutowireSetter;
use base\Attribute\AutowireMethod;
use AdminService\Log;

/**
 * 自相矛盾的标注用例(四种矛盾同放一个文件, 便于集中验证)
 *
 * - 都应当抛 `AutowireException`: 一个位置不能同时是"配置值注入"与"服务注入/生命周期钩子",
 *   也不能在"按类型注入服务"的 Setter 形参上标 `#[Config]`(那条路径不会应用它, 属静默失效)
 * - 注: PSR-4 下只有与本文件同名的类可被按名加载, 因此四者在同一个用例里一并断言
 */

/**
 * 属性同时标 #[Config] 与 #[AutowireProperty]
 */
class ConfigOnPropertyConflict {

    /**
     * 矛盾属性
     * @var mixed
     */
    #[Config('data.ext_name')]
    #[AutowireProperty(Log::class)]
    public $value='default';

}

/**
 * 方法同时标 #[Config] 与 #[AutowireSetter]
 */
class ConfigOnSetterConflict {

    /**
     * 矛盾方法
     *
     * @param mixed $value 值
     * @return void
     */
    #[Config('data.ext_name')]
    #[AutowireSetter(Log::class)]
    public function setValue($value): void {
    }

}

/**
 * `#[AutowireSetter]` 方法的形参上还标了 #[Config](该路径按类型注入服务, `#[Config]` 不会被应用)
 */
class ConfigOnSetterParamConflict {

    /**
     * 矛盾方法
     *
     * @param mixed $value 值
     * @return void
     */
    #[AutowireSetter(Log::class)]
    public function setValue(#[Config('data.ext_name')] $value=null): void {
    }

}

/**
 * 方法同时标 #[Config] 与 #[AutowireMethod](此前会被调用两次)
 */
class ConfigOnMethodConflict {

    /**
     * 矛盾方法
     *
     * @param mixed $value 值
     * @return void
     */
    #[Config('data.ext_name')]
    #[AutowireMethod]
    public function boot($value=null): void {
    }

}
