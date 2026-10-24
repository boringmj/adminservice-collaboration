<?php

namespace AdminService\ResponseProcessor;

use AdminService\Exception;
use base\AbstractResponseProcessor;

use function is_array;
use function is_object;
use function json_encode;
use function json_last_error_msg;

/**
 * JSON响应处理器
 */
class Json extends AbstractResponseProcessor {
   
    /**
     * 处理响应数据
     *
     * @access public
     * @return void
     */
    public function handle(): void {
        $temp=$this->getResponse()->body();
        // 获取flag
        $flag=$this->config['flag']??0;
        // 将不为数组和对象的值使用数组包裹
        if(!is_array($temp)&&!is_object($temp)) {
            $temp=[$temp];
        }
        $temp=json_encode($temp,$flag);
        if($temp===false)
            throw new Exception('JSON 响应序列化失败: '.json_last_error_msg());
        $this->getResponse()->rendered($temp);
    }

}