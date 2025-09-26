<?php

namespace Tests\Fixtures;

/**
 * 服务接口
 * @package Tests\Fixtures
 */
interface ServiceInterface {

    /**
     * 执行
     * @return void
     */
    public function execute(): string;

}