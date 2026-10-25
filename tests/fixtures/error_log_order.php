<?php

/**
 * 错误日志与错误页输出顺序夹具
 *
 * - 用最小假响应/视图/日志替换真实实现, 只观察日志写入相对错误页输出的顺序
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Container;
use AdminService\Error;
use AdminService\Log;

use base\Response as BaseResponse;
use base\View as BaseView;

/**
 * 不发送真实响应头的假响应
 */
class OrderResponse {

    public function status(int $code): void {
    }

    public function html(): void {
    }

    public function header(string $name,string $value): void {
    }

    public function sendHeaders(): void {
    }

}

/**
 * 最小假视图
 */
class OrderView {

    public function init(string $template_path,array $data=array()): void {
    }

    public function initWithContent(string $template_content,array $data=array()): void {
    }

    public function render(): string {
        return '|PAGE|';
    }

}

/**
 * 会输出标记的假日志
 */
class OrderLog extends Log {

    public function __construct() {
    }

    public function write(string $content,array $vars=array()): void {
        echo '|LOG|';
    }

}

$container=new Container();
$container->instance(BaseResponse::class,new OrderResponse());
$container->instance(BaseView::class,new OrderView());
$container->instance(Log::class,new OrderLog());

Error::setContainer($container);
Error::setInitialized(true);
Error::register(null,true);

trigger_error('order-test',E_USER_WARNING);
Error::handleShutdown();
echo '|END|';
