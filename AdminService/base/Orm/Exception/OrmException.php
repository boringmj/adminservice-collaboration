<?php

namespace base\Orm\Exception;

use base\Exception as BaseException;

/**
 * 对象关系映射层异常
 *
 * - ORM 语义错误(关系操作不受支持、父模型未持久化等)统一抛出该类
 * - 继承 base\Exception, 融入框架异常体系
 */
class OrmException extends BaseException {

}
