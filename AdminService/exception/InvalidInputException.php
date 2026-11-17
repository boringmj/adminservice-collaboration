<?php

namespace AdminService\exception;

use AdminService\Exception;
use base\HttpStatusExceptionInterface;

/**
 * 客户端输入异常
 *
 * - 对应 HTTP 400
 */
final class InvalidInputException extends Exception implements HttpStatusExceptionInterface {

    /**
     * HTTP 状态码
     *
     * @access public
     * @return int
     */
    public function statusCode(): int {
        return 400;
    }

}
