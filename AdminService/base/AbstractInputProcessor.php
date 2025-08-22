<?php

namespace base;

/**
 * Input处理类
 */
abstract class AbstractInputProcessor {

    /**
     * 数据
     * @var array
     */
    protected array $data=[];

    /**
     * 构造方法
     * 
     * @access public
     * @param string $data 数据
     * @return array
     */
    public function __construct(string $data) {
        $this->data=$this->handle($data);
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