<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\HttpRequest;
use AdminService\Response;
use AdminService\View;
use base\Controller;

use function AdminService\common\json as controller_json;

/**
 * 控制器快捷方法的状态码保留测试
 *
 * - `json()`/`html()` 未显式传状态码时不得覆盖已设状态码
 * - `controller_helper` 的 `json()` 同语义
 */
class ControllerResponseTest extends TestCase {

    /**
     * 测试控制器 json()/html() 未显式传状态码时不覆盖已设状态码
     * @return void
     */
    public function testControllerShortcutsKeepStatusWithoutExplicitCode(): void {
        $response=new Response();
        $controller=new class(new HttpRequest(),$response,new View()) extends Controller {

            /**
             * 暴露 json 快捷方法供测试
             * @access public
             * @param mixed $data 数据
             * @param int|null $code 状态码(null = 不改动)
             * @return mixed
             */
            public function exposeJson(mixed $data,?int $code=null): mixed {
                return $this->json($data,$code);
            }

            /**
             * 暴露 html 快捷方法供测试
             * @access public
             * @param mixed $html 内容
             * @param int|null $code 状态码(null = 不改动)
             * @return mixed
             */
            public function exposeHtml(mixed $html,?int $code=null): mixed {
                return $this->html($html,$code);
            }
        };
        $response->status(404);
        $this->assertSame(array('ok'=>true),$controller->exposeJson(array('ok'=>true)));
        $this->assertSame(404,$response->status(),'json() 未显式传码不得覆盖已设状态码');
        $this->assertSame('<b>x</b>',$controller->exposeHtml('<b>x</b>'));
        $this->assertSame(404,$response->status(),'html() 未显式传码不得覆盖已设状态码');
        // 显式传码仍然生效
        $controller->exposeJson(array('ok'=>true),201);
        $this->assertSame(201,$response->status());
        $controller->exposeHtml('<b>x</b>',200);
        $this->assertSame(200,$response->status());
    }

    /**
     * 测试 controller_helper 的 json() 未显式传状态码时不覆盖已设状态码
     * @return void
     */
    public function testHelperJsonKeepsStatusWithoutExplicitCode(): void {
        $previous=App::getInstance();
        $response=new Response(new Repository(array()));
        App::setInstance(new Container());
        try {
            App::getInstance()->instance(\base\Response::class,$response);
            $response->status(404);
            $this->assertSame(array('ok'=>true),controller_json(array('ok'=>true)));
            $this->assertSame(404,$response->status(),'helper json() 未显式传码不得覆盖已设状态码');
            $this->assertSame(array('ok'=>true),controller_json(array('ok'=>true),201));
            $this->assertSame(201,$response->status());
        } finally {
            App::setInstance($previous);
        }
    }

}
