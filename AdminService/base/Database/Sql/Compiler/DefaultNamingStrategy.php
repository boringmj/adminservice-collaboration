<?php

namespace base\Database\Sql\Compiler;

/**
 * 默认命名策略
 *
 * - 原样透传表名、列名与别名
 * - 需要自定义命名约定(如驼峰转下划线)时实现 NamingStrategyInterface
 */
final class DefaultNamingStrategy implements NamingStrategyInterface {

    /**
     * 处理表名
     *
     * @access public
     * @param string $name 表名
     * @return string
     */
    public function table(string $name): string {
        return $name;
    }

    /**
     * 处理列名
     *
     * @access public
     * @param string $name 列名
     * @return string
     */
    public function column(string $name): string {
        return $name;
    }

    /**
     * 处理别名
     *
     * @access public
     * @param string $name 别名
     * @return string
     */
    public function alias(string $name): string {
        return $name;
    }

}
