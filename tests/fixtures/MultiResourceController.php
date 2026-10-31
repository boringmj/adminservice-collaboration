<?php

namespace Tests\Fixtures;

use base\Attribute\Resource;

/**
 * 测试用多资源根注解控制器
 *
 * - 两个资源根声明在同一控制器上, 验证 Resource 可重复
 */
#[Resource('/alpha')]
#[Resource('/beta')]
class MultiResourceController extends ResourceController {
}
