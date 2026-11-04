<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Application;
use AdminService\Config;
use AdminService\Container;
use AdminService\ContextStorage;
use AdminService\Error;
use AdminService\Response;
use Tests\Fixtures\RequestScopedDependency;
use Tests\Fixtures\RequestScopedResponseProcessor;

/**
 * 响应出口请求作用域测试
 */
class ResponseScopeTest extends TestCase {

    /**
     * 每个用例使用干净容器
     *
     * @return void
     */
    protected function setUp(): void {
        App::setInstance(new Container());
        App::init();
    }

    /**
     * 清理请求上下文
     *
     * @return void
     */
    protected function tearDown(): void {
        Error::setRequestContext(null);
        Error::setRequestContainer(null);
        ContextStorage::reset();
        parent::tearDown();
    }

    /**
     * 测试: handle 结束后响应处理器仍从请求容器解析依赖
     *
     * @return void
     */
    public function testResponseProcessorUsesRequestContainerAfterHandle(): void {
        $app_container=App::getInstance();
        $app_container->instance(RequestScopedDependency::class,new RequestScopedDependency('app'));
        $old_types=Config::get('response.default.type');
        Config::setValue('response.default.type',array(
            '*/*'=>array('class'=>RequestScopedResponseProcessor::class)
        ));
        try {
            $response=null;
            $application=new Application();
            $application->handle(function() use (&$response): void {
                App::getInstance()->instance(
                    RequestScopedDependency::class,
                    new RequestScopedDependency('request')
                );
                $response=App::get(Response::class);
                $response->body('body');
            });

            $this->assertInstanceOf(Response::class,$response);
            $this->assertSame('request',$response->render());
        } finally {
            Config::setValue('response.default.type',$old_types);
        }
    }

}
