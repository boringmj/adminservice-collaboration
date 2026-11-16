<?php

namespace app\demo\controller;

use base\Controller;

use function class_exists;
use function getmypid;
use function spl_object_id;

/**
 * 常驻 worker 示例
 *
 * - 返回进程 ID、协程 ID 与请求对象标识, 便于观察进程与请求隔离
 */
class WorkerDemo extends Controller {

    /**
     * 当前进程与请求信息
     *
     * @access public
     * @return array<string,int|string>
     */
    public function info(): array {
        $coroutine=-1;
        if(class_exists(\Swoole\Coroutine::class,false))
            $coroutine=\Swoole\Coroutine::getCid();
        return array(
            'sapi'=>PHP_SAPI,
            'pid'=>getmypid(),
            'coroutine'=>$coroutine,
            'request'=>spl_object_id($this->request),
            'uri'=>(string)$this->request->server('REQUEST_URI')
        );
    }

}
