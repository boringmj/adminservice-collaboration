<?php

use AdminService\Router\Router;
use \app\demo\controller\Index;
use \app\demo\controller\Autowire;
use \app\demo\controller\DatabaseDemo;
use \app\demo\controller\OrmDemo;
use \app\demo\controller\ProducesDemo;
use \app\demo\controller\StreamDemo;
use \app\demo\controller\WorkerDemo;

/**
 * demo 应用路由
 *
 * - 分组可嵌套: 外层声明应用前缀, 内层声明各控制器前缀, 前缀依次拼接
 * - 演示路由使用 `any()`: 原约定式路由不区分请求方法, 此处保持一致
 */

return function(Router $router): void {

    $router->group(array('prefix'=>'/demo'),function(Router $router): void {

        // 应用默认控制器
        $router->any('/',array(Index::class,'index'));

        $router->group(array('prefix'=>'/autowire'),function(Router $router): void {
            $router->any('/',array(Autowire::class,'index'));
            $router->any('/index',array(Autowire::class,'index'));
        });

        $router->group(array('prefix'=>'/databaseDemo'),function(Router $router): void {
            $router->any('/',array(DatabaseDemo::class,'index'));
            $router->any('/index',array(DatabaseDemo::class,'index'));
            $router->any('/demo',array(DatabaseDemo::class,'demo'));
            $router->any('/facade',array(DatabaseDemo::class,'facade'));
        });

        $router->group(array('prefix'=>'/index'),function(Router $router): void {
            $router->any('/',array(Index::class,'index'));
            // 子路径即方法名, 逐个注册
            foreach(array('index','request','view_demo','log','exec','upload','curl') as $method)
                $router->any('/'.$method,array(Index::class,$method));
            // 校验演示: 形参 $name 由路径参数注入(查询参数不参与形参注入)
            $router->any('/validator/{name?}',array(Index::class,'validator'));
        });

        $router->group(array('prefix'=>'/ormDemo'),function(Router $router): void {
            $router->any('/',array(OrmDemo::class,'index'));
            // 子路径即方法名, 逐个注册
            foreach(array('index','schema','relation','prefixCheck','transaction','addUpdatedAt') as $method)
                $router->any('/'.$method,array(OrmDemo::class,$method));
        });

        // 可生产内容类型: Accept: application/json / text/html / text/plain
        $router->group(array('prefix'=>'/produces'),function(Router $router): void {
            $router->get('/',array(ProducesDemo::class,'flexible'));
            $router->get('/json',array(ProducesDemo::class,'jsonOnly'));
            $router->get('/flexible',array(ProducesDemo::class,'flexible'));
            $router->get('/text',array(ProducesDemo::class,'textOnly'));
            $router->get('/chain',array(ProducesDemo::class,'chain'))->produces('application/json');
        });

        // 流式响应: /demo/stream/events (SSE) 与 /demo/stream/text
        $router->group(array('prefix'=>'/stream'),function(Router $router): void {
            $router->get('/',array(StreamDemo::class,'events'));
            $router->get('/events',array(StreamDemo::class,'events'));
            $router->get('/text',array(StreamDemo::class,'text'));
        });

        // 常驻 worker: 观察进程 / 协程 / 请求对象标识
        $router->group(array('prefix'=>'/worker'),function(Router $router): void {
            $router->get('/',array(WorkerDemo::class,'info'));
            $router->get('/info',array(WorkerDemo::class,'info'));
        });

    });

};
