<?php

namespace Tests\Fixtures;

use base\Attribute\ApiResource;

/**
 * 测试用多 API 资源根注解控制器
 *
 * - 两个 API 资源根声明在同一控制器上, 验证 ApiResource 可重复
 */
#[ApiResource('/gamma')]
#[ApiResource('/delta')]
class MultiApiResourceController extends ApiResourceController {
}
