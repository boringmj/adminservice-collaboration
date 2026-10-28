<?php

namespace base\Attribute;

/**
 * 自动注入模式
 *
 * - `Normal`: 直接注入真实对象
 * - `Proxy`: 注入 `AdminService\DynamicProxy`, 目标由容器惰性解析
 * - `Deferred`: 根对象完成装配后再解析并注入真实对象
 *
 * 模式是注入目标注解的参数, 不是独立注解:
 * 这样不会出现"只有模式、没有注入目标"的孤立标注。
 */
enum AutowireMode: string {

    /**
     * 直接注入真实对象
     */
    case Normal='normal';

    /**
     * 注入动态代理
     */
    case Proxy='proxy';

    /**
     * 延迟到根对象装配完成后注入
     */
    case Deferred='deferred';

}
