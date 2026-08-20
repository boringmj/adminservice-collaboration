<?php

namespace base\Database\Result;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function count;

/**
 * 结果集集合
 *
 * - 承载查询返回的数据行(关联数组列表)
 * - 可迭代、可计数
 */
class AbstractCollection implements Countable, IteratorAggregate {

    /**
     * 数据行
     * @var array
     */
    protected array $rows;

    /**
     * 构造方法
     *
     * @access public
     * @param array $rows 数据行列表
     */
    public function __construct(array $rows=array()) {
        $this->rows=$rows;
    }

    /**
     * 转换为数组
     *
     * @access public
     * @return array
     */
    public function toArray(): array {
        return $this->rows;
    }

    /**
     * 获取数据行数量
     *
     * @access public
     * @return int
     */
    public function count(): int {
        return count($this->rows);
    }

    /**
     * 是否为空
     *
     * @access public
     * @return bool
     */
    public function isEmpty(): bool {
        return empty($this->rows);
    }

    /**
     * 获取迭代器
     *
     * @access public
     * @return Traversable
     */
    public function getIterator(): Traversable {
        return new ArrayIterator($this->rows);
    }

}
