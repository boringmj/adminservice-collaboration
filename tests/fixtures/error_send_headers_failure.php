<?php

/**
 * 错误出口响应头发送失败夹具
 *
 * - 响应的 sendHeaders() 固定抛异常
 * - 错误页仍须输出, 不能因为响应头发送失败而丢失正文
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Container;
use AdminService\Error;
use AdminService\Log;

use base\Response as BaseResponse;
use base\View as BaseView;

$log_file=$argv[1]??'';
if($log_file!=='')
    ini_set('error_log',$log_file);

/**
 * 发送响应头时抛异常的假响应
 */
class HeaderFailureResponse {

    public function status(int $code): void {
    }

    public function html(): void {
    }

    public function header(string $name,string $value): void {
    }

    public function sendHeaders(): void {
        throw new RuntimeException('headers failed');
    }

}

/**
 * 最小假视图
 */
class HeaderFailureView {

    public function init(string $template_path,array $data=array()): void {
    }

    public function initWithContent(string $template_content,array $data=array()): void {
    }

    public function render(): string {
        return '|PAGE|';
    }

}

/**
 * 不输出内容的假日志
 */
class HeaderFailureLog extends Log {

    public function __construct() {
    }

    public function write(string $content,array $vars=array()): void {
    }

}

$container=new Container();
$container->instance(BaseResponse::class,new HeaderFailureResponse());
$container->instance(BaseView::class,new HeaderFailureView());
$container->instance(Log::class,new HeaderFailureLog());

Error::setContainer($container);
Error::setInitialized(true);
Error::register(null,true);

trigger_error('header failure test',E_USER_WARNING);
Error::handleShutdown();
echo '|END|';
