<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Application;
use AdminService\Config;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\Exception;
use AdminService\NativeSession;
use AdminService\Worker\RequestFactory;
use AdminService\Worker\Worker;

/**
 * 常驻 worker 核心测试
 */
class WorkerTest extends TestCase {

    /**
     * 测试: 连续请求复用同一应用, 请求结束后不保留上下文
     *
     * @return void
     */
    public function testHandlesRequestsInSequence(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $config=Config::new(Config::all());
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        try {
            $first=$worker->handle(RequestFactory::create('GET','/demo/index/index'));
            $this->assertSame(200,$first->status());
            $this->assertSame('Hello World!',trim($first->body()));
            $this->assertNull($app->requestContext(),'请求结束后不应保留请求上下文');

            $second=$worker->handle(RequestFactory::create('GET','/demo/index/index'));
            $this->assertSame(200,$second->status());
            $this->assertSame('Hello World!',trim($second->body()));
            $this->assertNull($app->requestContext());
        } finally {
            $worker->shutdown();
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
        }
    }

    /**
     * 测试: 未命中路由仍返回框架响应
     *
     * @return void
     */
    public function testHandlesMissingRoute(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $config=Config::new(Config::all());
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        try {
            $response=$worker->handle(RequestFactory::create('GET','/missing'));
            $this->assertSame(404,$response->status());
            $this->assertNotSame('',$response->body());
        } finally {
            $worker->shutdown();
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
        }
    }

    /**
     * 测试: worker 拒绝原生会话
     *
     * @return void
     */
    public function testRejectsNativeSession(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $configs=Config::all();
        $configs['session']['class']=NativeSession::class;
        $config=Config::new($configs);
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        try {
            $this->expectException(Exception::class);
            $worker->boot();
        } finally {
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
        }
    }

}
