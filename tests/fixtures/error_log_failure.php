<?php

/**
 * 错误出口日志写入失败夹具
 *
 * - 日志服务固定抛异常
 * - 页面仍须输出, 且提示必须是"日志记录失败", 不能混成响应头失败
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
 * 不发送真实响应头的假响应
 */
class LogFailureResponse {

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
class LogFailureView {

    public function init(string $template_path,array $data=array()): void {
    }

    public function initWithContent(string $template_content,array $data=array()): void {
    }

    public function render(): string {
        return '|PAGE|';
    }

}

/**
 * 固定失败的日志服务
 */
class LogFailureLog extends Log {

    public function __construct() {
    }

    public function write(string $content,array $vars=array()): void {
        throw new RuntimeException('log failed');
    }

}

$container=new Container();
$container->instance(BaseResponse::class,new LogFailureResponse());
$container->instance(BaseView::class,new LogFailureView());
$container->instance(Log::class,new LogFailureLog());

Error::setContainer($container);
Error::setInitialized(true);
Error::register(null,true);

trigger_error('log failure test',E_USER_WARNING);
Error::handleShutdown();
echo '|END|';
