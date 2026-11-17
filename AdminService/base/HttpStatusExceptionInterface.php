<?php

namespace base;

/**
 * 带 HTTP 状态码的异常
 *
 * - 错误出口按 `statusCode()` 设置响应状态
 * - 未实现本接口的异常仍按 500 处理
 */
interface HttpStatusExceptionInterface {

    /**
     * HTTP 状态码
     *
     * @access public
     * @return int
     */
    public function statusCode(): int;

}
