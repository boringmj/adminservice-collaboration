<?php

namespace AdminService\exception;

use AdminService\Exception;
use AdminService\exception\UploadExceptionInterface;

/**
 * 文件上传异常
 */
class UploadException extends Exception implements UploadExceptionInterface { }