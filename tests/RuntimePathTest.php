<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Container;
use AdminService\Runtime;
use AdminService\RuntimePath;
use base\RuntimePathInterface;

/**
 * 运行时路径解析契约测试
 */
class RuntimePathTest extends TestCase {

    /**
     * 测试: 默认实现委托 Runtime::path()
     *
     * @return void
     */
    public function testDefaultResolverDelegatesToRuntime(): void {
        $resolver=new RuntimePath();
        $this->assertSame(Runtime::path(),$resolver->path());
    }

    /**
     * 测试: 容器可按契约解析默认实现
     *
     * @return void
     */
    public function testContainerResolvesRuntimePathContract(): void {
        $container=new Container();
        $container->alias(RuntimePathInterface::class,RuntimePath::class);

        $resolver=$container->get(RuntimePathInterface::class);
        $this->assertInstanceOf(RuntimePath::class,$resolver);
    }

}
