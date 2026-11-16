<?php

namespace app\demo\controller;

use AdminService\Coroutine;
use base\Controller;

/**
 * 流式响应示例
 *
 * - FPM 下逐段输出并刷新; 常驻 worker 下按块写出
 * - `on_disconnect=continue` 表示客户端断开后继续执行生成器
 */
class StreamDemo extends Controller {

    /**
     * Server-Sent Events 示例
     *
     * - `text/event-stream` 未在 `response.default.type` 登记, 原样作为 Content-Type
     *
     * @access public
     * @return void
     */
    public function events(): void {
        $this->stream(function(callable $write): void {
            for($i=1;$i<=5;$i++) {
                $write('data: 第 '.$i.' 段'.PHP_EOL.PHP_EOL);
                Coroutine::sleep(0.2);
            }
        },'text/event-stream',array('on_disconnect'=>'continue'));
    }

    /**
     * 纯文本流式输出
     *
     * @access public
     * @return void
     */
    public function text(): void {
        $this->stream(function(callable $write): void {
            for($i=1;$i<=5;$i++) {
                $write('第 '.$i.' 段'.PHP_EOL);
                Coroutine::sleep(0.2);
            }
        },'text/plain');
    }

}
