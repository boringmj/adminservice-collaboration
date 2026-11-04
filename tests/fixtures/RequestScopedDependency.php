<?php

namespace Tests\Fixtures;

/**
 * 请求作用域测试依赖
 */
final class RequestScopedDependency {

    /**
     * 标识值
     * @var string
     */
    public string $value;

    /**
     * 构造方法
     *
     * @param string $value 标识值
     */
    public function __construct(string $value) {
        $this->value=$value;
    }

}
