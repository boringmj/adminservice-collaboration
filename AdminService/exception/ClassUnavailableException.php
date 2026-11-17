<?php

namespace AdminService\exception;

use AdminService\Exception;

/**
 * 依赖类不可用异常
 *
 * - 表示类不存在或不可实例化, 供联合类型选择分支时判断"该分支不可构造"
 */
class ClassUnavailableException extends Exception {

}
