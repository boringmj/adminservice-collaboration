<?php

namespace base;

/**
 * Input处理类
 *
 * - 解析须显式调用 `parse()`, 不在构造函数中产生副作用
 */
abstract class AbstractInputProcessor {

    /**
     * 数据
     * @var array
     */
    protected array $data=[];

    /**
     * 解析数据
     *
     * @access public
     * @param string $data 数据
     * @return static
     */
    public function parse(string $data): static {
        $this->data=$this->handle($data);
        return $this;
    }

    /**
     * 获取处理后的数据
     *
     * @access public
     * @return array
     */
    public function toArray(): array {
        return $this->data;
    }

    /**
     * 处理input数据
     *
     * @access protected
     * @param string $data 数据
     * @return array
     */
    abstract protected function handle(string $data): array;

}
