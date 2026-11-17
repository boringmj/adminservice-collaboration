<?php

namespace AdminService\View\Compiler;

use Closure;
use base\View\CompiledTemplateInterface;

/**
 * PHP 闭包产物
 *
 * @access public
 * @package AdminService\View\Compiler
 * @version 1.0.0
 */
final class CompiledClosure implements CompiledTemplateInterface {

    /**
     * 构造方法
     *
     * @access public
     * @param Closure(array<string,mixed>):string $render 渲染闭包
     */
    public function __construct(private Closure $render) {
    }

    /**
     * 渲染模板
     *
     * @access public
     * @param array<string,mixed> $data 模板数据
     * @return string
     */
    public function render(array $data): string {
        return ($this->render)($data);
    }

}
