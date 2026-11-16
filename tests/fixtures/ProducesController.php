<?php

namespace Tests\Fixtures;

use base\Attribute\Produces;
use base\Controller;

/**
 * 可生产内容类型声明的控制器
 */
class ProducesController extends Controller {

    /**
     * 处理器执行标记
     * @var string
     */
    public static string $marker='';

    /**
     * 只声明 JSON
     *
     * @access public
     * @return array<string,bool>
     */
    #[Produces('application/json')]
    public function jsonOnly(): array {
        return array('ok'=>true);
    }

    /**
     * 声明 JSON 与 HTML, 显式返回 HTML
     *
     * @access public
     * @return mixed
     */
    #[Produces(array('application/json','text/html'))]
    public function multi(): mixed {
        $this->type('text/html');
        return 'html';
    }

    /**
     * 声明 JSON 但显式返回纯文本
     *
     * @access public
     * @return string
     */
    #[Produces('application/json')]
    public function wrongType(): string {
        $this->type('text/plain');
        return 'plain';
    }

    /**
     * 带副作用标记
     *
     * @access public
     * @return string
     */
    #[Produces('application/json')]
    public function sideEffect(): string {
        self::$marker='executed';
        return 'ok';
    }

    /**
     * 错误状态码下使用纯文本
     *
     * @access public
     * @return string
     */
    #[Produces('application/json')]
    public function errorStatus(): string {
        $this->code(404);
        $this->type('text/plain');
        return '404 Not Found';
    }

    /**
     * 返回 204
     *
     * @access public
     * @return string
     */
    #[Produces('application/json')]
    public function noContent(): string {
        $this->code(204);
        return '';
    }

    /**
     * 204 且显式设置非声明类型
     *
     * @access public
     * @return string
     */
    #[Produces('application/json')]
    public function noContentPlain(): string {
        $this->code(204);
        $this->type('text/plain');
        return '';
    }

    /**
     * 返回未登记的 CSV 类型
     *
     * @access public
     * @return string
     */
    #[Produces('text/csv')]
    public function csv(): string {
        return 'a,b';
    }

    /**
     * 未声明可生产类型
     *
     * @access public
     * @return string
     */
    public function plain(): string {
        return 'plain';
    }

}
