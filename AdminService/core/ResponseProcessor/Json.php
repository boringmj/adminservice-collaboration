<?php

namespace AdminService\ResponseProcessor;

use base\AbstractResponseProcessor;

/**
 * JSON响应处理器
 */
class Json extends AbstractResponseProcessor {
   
    /**
     * 处理响应数据
     * 
     * @access protected
     * @return void
     */
    protected function handle(): void {
        $temp=$this->getResponse()->getControllerReturn();
        // 获取flag
        $flag=$this->config['flag']??0;
        // 将不为数组和对象的值使用数组包裹
        if(!is_array($temp)&&!is_object($temp)) {
            $temp=['data'=>$temp];
        }
        $temp=json_encode($temp,$flag);
        $this->getResponse()->setReturnContent($temp);
    }

}