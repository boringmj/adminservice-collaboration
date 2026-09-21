<?php

namespace AdminService;

use base\AbstractInputProcessor;

use function parse_str;

/**
 * 表单请求体解析器
 *
 * - 解析 `application/x-www-form-urlencoded` 请求体
 * - 主要服务 PHP 不会填充 `$_POST` 的请求方法(PUT / PATCH / DELETE)
 * - `multipart/form-data` 的请求体无法从原始数据还原, 这些方法暂不支持
 */
class FormInput extends AbstractInputProcessor {

    /**
     * 处理input数据
     *
     * @access protected
     * @param string $data 数据
     * @return array
     */
    protected function handle(string $data): array {
        if($data==='')
            return array();
        $parsed=array();
        parse_str($data,$parsed);
        return $parsed;
    }

}
