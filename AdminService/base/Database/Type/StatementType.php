<?php

namespace base\Database\Type;

/**
 * 语句类型
 *
 * - 出于兼容性考虑, 不使用8.1 新增的枚举类型
 */
final class StatementType {

    /**
     * 查询多条记录
     * @var int
     */
    public const SELECT=1;

    /**
     * 查询单条记录(编译器自动附加 LIMIT 1)
     * @var int
     */
    public const FIND=2;

    /**
     * 统计记录数(编译器生成 COUNT 语句)
     * @var int
     */
    public const COUNT=3;

    /**
     * 插入记录
     * @var int
     */
    public const INSERT=4;

    /**
     * 更新记录
     * @var int
     */
    public const UPDATE=5;

    /**
     * 删除记录
     * @var int
     */
    public const DELETE=6;

}
