<?php

namespace base\View;

/**
 * 可渲染的模板产物
 *
 * @access public
 * @package base\View
 * @version 1.0.0
 */
interface CompiledTemplateInterface {

    /**
     * 渲染模板
     *
     * @access public
     * @param array<string,mixed> $data 模板数据
     * @return string
     */
    public function render(array $data): string;

}
