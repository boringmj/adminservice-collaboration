<?php

namespace AdminService;

/**
 * 当前请求上下文存储入口
 *
 * - 默认使用 CoroutineContextStorage；无协程扩展时回落 Fiber/顺序栈
 * - 门面与错误出口通过 current() 获取当前请求，不依赖 App 指针
 */
final class ContextStorage {

    /**
     * 当前存储实现
     * @var ContextStorageInterface|null
     */
    private static ?ContextStorageInterface $storage=null;

    /**
     * 获取存储实现
     *
     * @access public
     * @return ContextStorageInterface
     */
    public static function storage(): ContextStorageInterface {
        return self::$storage??=new CoroutineContextStorage();
    }

    /**
     * 替换存储实现(测试或运行时适配)
     *
     * @access public
     * @param ContextStorageInterface $storage 存储实现
     * @return void
     */
    public static function setStorage(ContextStorageInterface $storage): void {
        self::$storage=$storage;
    }

    /**
     * 重置为默认实现
     *
     * @access public
     * @return void
     */
    public static function reset(): void {
        self::$storage=null;
    }

    /**
     * 获取当前请求上下文
     *
     * @access public
     * @return RequestContext|null
     */
    public static function current(): ?RequestContext {
        return self::storage()->current();
    }

    /**
     * 在指定上下文中执行回调
     *
     * @access public
     * @param RequestContext $context 请求上下文
     * @param callable(RequestContext):mixed $callback 回调
     * @return mixed
     */
    public static function run(RequestContext $context,callable $callback): mixed {
        return self::storage()->run($context,$callback);
    }

}
