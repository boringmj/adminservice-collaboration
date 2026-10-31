<?php

namespace Tests\Fixtures;

/**
 * 测试用资源注解继承控制器
 *
 * - 父类的类级 #[Resource] 不应被子类继承
 */
class InheritedResourceController extends ResourceController {
}
