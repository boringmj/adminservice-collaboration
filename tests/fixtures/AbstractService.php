<?php

namespace Tests\Fixtures;

/**
 * 服务抽象类
 * @package Tests\Fixtures
 */
abstract class AbstractService implements ServiceInterface {

    /**
     * @inheritDoc
     */
    public function execute(): string {
        return "Executed by AbstractService";
    }

    /**
     * 额外的方法
     * @return string
     */
    abstract public function additional(): string;

}