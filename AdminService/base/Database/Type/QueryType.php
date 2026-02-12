<?php
namespace base\Database\Type;

/**
 * 查询类型
 * 
 * - 出于兼容性考虑, 不使用8.1 新增的枚举类型
 */
final class QueryType {

    /**
     * 允许读取
     * @var int
     */
    public const READ=0x02;
    
    /**
     * 允许写入
     * @var int
     */
    public const WRITE=0x04;

    /**
     * 允许读写
     * @var int
     */
    public const READ_WRITE=self::READ|self::WRITE;

}