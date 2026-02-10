<?php
namespace base\Database\Type;

/**
 * 连接类型
 * 
 * - 出于兼容性考虑, 不使用8.1 新增的枚举类型
 */
final class ConnectionType {

    /**
     * 共享连接
     * @var int
     */
    public const SHARE=1;

    /**
     * 独占连接
     * @var int
     */
    public const EXCLUSIVE=2;
    
}