<?php

namespace app\demo\controller;

use base\Attribute\Produces;
use base\Controller;

/**
 * 可生产内容类型示例
 *
 * - 方法上的 `#[Produces]` 会在处理器执行前完成内容协商
 * - 访问时用 `Accept` 指定 `application/json` 或 `text/html`
 */
class ProducesDemo extends Controller {

    /**
     * 只声明 JSON
     *
     * @access public
     * @return array<string,string>
     */
    #[Produces('application/json')]
    public function jsonOnly(): array {
        return array(
            'message'=>'只接受 application/json',
            'type'=>$this->response->contentType()
        );
    }

    /**
     * 同时声明 JSON 与 HTML, 按协商结果返回不同正文
     *
     * @access public
     * @return mixed
     */
    #[Produces(array('application/json','text/html'))]
    public function flexible(): mixed {
        if($this->response->contentType()==='text/html')
            return '<h1>HTML 分支</h1><p>Accept 命中了 text/html</p>';
        return array(
            'message'=>'JSON 分支',
            'type'=>$this->response->contentType()
        );
    }

    /**
     * 只声明纯文本
     *
     * @access public
     * @return string
     */
    #[Produces('text/plain')]
    public function textOnly(): string {
        return '只接受 text/plain';
    }

    /**
     * 由显式路由的 `produces()` 声明
     *
     * @access public
     * @return array<string,string>
     */
    public function chain(): array {
        return array(
            'message'=>'显式路由链式声明 produces',
            'type'=>$this->response->contentType()
        );
    }

}
