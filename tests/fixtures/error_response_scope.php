<?php

/**
 * 错误出口响应作用域夹具
 *
 * - 应用级与请求级容器分别绑定不同的假响应
 * - 触发已收集错误后, 错误出口应使用请求级响应
 * - 参数 1: 标记文件路径
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Container;
use AdminService\Error;
use base\Response;

final class MarkerResponse {

    private string $label;

    private string $marker;

    public function __construct(string $label,string $marker) {
        $this->label=$label;
        $this->marker=$marker;
    }

    public function status(?int $code=null): int {
        return $code??200;
    }

    public function html(null|string|int|bool $content=null): mixed {
        return $content;
    }

    public function header(string $name,string|array|null $value=null): string {
        return '';
    }

    public function sendHeaders(): void {
        file_put_contents($this->marker,$this->label."\n",FILE_APPEND);
    }

    public function render(): string {
        return 'rendered';
    }

    public function rendered(?string $content=null): ?string {
        return 'rendered';
    }

}

$marker=$argv[1]??'';
$app_container=new Container();
$app_container->instance(Response::class,new MarkerResponse('app',$marker));
$request_container=new Container();
$request_container->instance(Response::class,new MarkerResponse('request',$marker));

Error::setContainer($app_container);
Error::setRequestContainer($request_container);
Error::register(null,true);
trigger_error('scope test',E_USER_WARNING);
