<?php

namespace base;

use Throwable;

/**
 * 文件上传异常接口
 *
 * - 上传链路抛出的异常实现该接口, 调用方可以统一捕获
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
interface UploadExceptionInterface extends Throwable {
}
