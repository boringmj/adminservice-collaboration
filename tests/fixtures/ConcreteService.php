<?php

namespace Tests\Fixtures;

/**
 * 服务实现类
 * @package Tests\Fixtures
 */
class ConcreteService extends AbstractService {

    /**
     * @inheritDoc
     */
    public function additional(): string {
        return "Additional logic in ConcreteService";
    }

}