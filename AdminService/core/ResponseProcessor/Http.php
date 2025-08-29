<?php

namespace AdminService\ResponseProcessor;

use base\AbstractResponseProcessor;

/**
 * HTTP响应处理器
 */
class Http extends AbstractResponseProcessor {
   
    /**
     * 处理响应数据
     * 
     * @access protected
     * @return void
     */
    protected function handle(): void {
        $temp=$this->getResponse()->getControllerReturn();
        // 将bool值转为字面量
        if(is_bool($temp)) {
            $temp=$temp?'true':'false';
            $this->getResponse()->setReturnContent($temp);
            return;
        }
        // 如果是数字型则转为字符串
        if(is_numeric($temp)) {
            $temp=(string)$temp;
            $this->getResponse()->setReturnContent($temp);
            return;
        }
        // 如果是数组或对象则转为json字符串
        if(is_array($temp)||is_object($temp)) {
            $temp=json_encode($temp);
            $this->getResponse()->setReturnContent($temp);
            return;
        }
        // 如果是字符串则直接返回
        if(is_string($temp)) {
            $this->getResponse()->setReturnContent($temp);
            return;
        }
    }

}