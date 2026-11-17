<?php

namespace AdminService;

use AdminService\exception\InvalidInputException;
use base\AbstractInputProcessor;

use function is_array;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;

class JsonInput extends AbstractInputProcessor {

    /**
     * 错误码: JSON 请求体解析失败
     */
    private const ERROR_INVALID_JSON=100401;

    /**
     * 处理input数据
     *
     * - 空请求体与合法但非数组的 JSON 返回空数组
     * - 解析失败抛出异常, 不把损坏的请求体当成空输入
     *
     * @access protected
     * @param string $data 数据
     * @return array
     * @throws InvalidInputException JSON 解析失败
     */
    protected function handle(string $data): array {
        if($data==='')
            return array();
        $decoded=json_decode($data,true);
        if(is_array($decoded))
            return $decoded;
        $error=json_last_error();
        if($error!==JSON_ERROR_NONE)
            throw new InvalidInputException('JSON input parse failed: '.json_last_error_msg(),self::ERROR_INVALID_JSON,array(
                'json_error'=>$error
            ));
        return array();
    }

}
