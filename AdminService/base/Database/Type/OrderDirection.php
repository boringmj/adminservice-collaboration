<?php

namespace base\Database\Type;

/**
 * 排序方向常量(通用 ANSI SQL)
 *
 * - 供 order()/orderBy() 的方向参数使用; 亦可直接传字面量(内部统一转大写)
 */
final class OrderDirection {

    /**
     * 升序
     * @var string
     */
    public const ASC='ASC';

    /**
     * 降序
     * @var string
     */
    public const DESC='DESC';

}
