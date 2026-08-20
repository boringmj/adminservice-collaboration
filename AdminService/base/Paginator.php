<?php

namespace base;

use function ceil;

/**
 * 分页结果
 *
 * - 承载一页模型集合与分页元信息
 * - 由查询构建器的 paginate() 产出
 */
class Paginator {

    /**
     * 当前页模型集合
     * @var ModelCollection
     */
    protected ModelCollection $items;

    /**
     * 总记录数
     * @var int
     */
    protected int $total;

    /**
     * 每页条数
     * @var int
     */
    protected int $perPage;

    /**
     * 当前页码
     * @var int
     */
    protected int $currentPage;

    /**
     * 总页数
     * @var int
     */
    protected int $lastPage;

    /**
     * 构造方法
     *
     * @access public
     * @param ModelCollection $items 当前页模型集合
     * @param int $total 总记录数
     * @param int $perPage 每页条数
     * @param int $currentPage 当前页码
     */
    public function __construct(ModelCollection $items,int $total,int $perPage,int $currentPage) {
        $this->items=$items;
        $this->total=$total;
        $this->perPage=$perPage;
        $this->currentPage=$currentPage;
        $this->lastPage=$perPage>0?(int)ceil($total/$perPage):0;
    }

    /**
     * 获取当前页模型集合
     *
     * @access public
     * @return ModelCollection
     */
    public function items(): ModelCollection {
        return $this->items;
    }

    /**
     * 获取当前页模型集合(别名)
     *
     * @access public
     * @return ModelCollection
     */
    public function getItems(): ModelCollection {
        return $this->items;
    }

    /**
     * 获取总记录数
     *
     * @access public
     * @return int
     */
    public function total(): int {
        return $this->total;
    }

    /**
     * 获取每页条数
     *
     * @access public
     * @return int
     */
    public function perPage(): int {
        return $this->perPage;
    }

    /**
     * 获取当前页码
     *
     * @access public
     * @return int
     */
    public function currentPage(): int {
        return $this->currentPage;
    }

    /**
     * 获取总页数
     *
     * @access public
     * @return int
     */
    public function lastPage(): int {
        return $this->lastPage;
    }

    /**
     * 是否还有下一页
     *
     * @access public
     * @return bool
     */
    public function hasMorePages(): bool {
        return $this->currentPage<$this->lastPage;
    }

    /**
     * 是否为空
     *
     * @access public
     * @return bool
     */
    public function isEmpty(): bool {
        return $this->items->isEmpty();
    }

    /**
     * 转为数组
     *
     * @access public
     * @return array
     */
    public function toArray(): array {
        return array(
            'items'=>$this->items->toArray(),
            'total'=>$this->total,
            'per_page'=>$this->perPage,
            'current_page'=>$this->currentPage,
            'last_page'=>$this->lastPage,
        );
    }

}
