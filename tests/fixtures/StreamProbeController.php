<?php

namespace Tests\Fixtures;

use base\Controller;

/**
 * 流式生成器状态探针
 */
class StreamProbeController extends Controller {

    /**
     * 生成器内执行的回调
     * @var callable|null
     */
    public static $probe=null;

    /**
     * 生成器被调用次数
     * @var int
     */
    public static int $calls=0;

    /**
     * 流式响应
     *
     * @access public
     * @return string
     */
    public function index(): string {
        $this->stream(function(callable $write): void {
            self::$calls++;
            if(is_callable(self::$probe))
                (self::$probe)();
            $write('probe');
        },'text/plain');
        return '';
    }

}
