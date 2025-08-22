<?php

namespace AdminService;

use base\AbstractInputProcessor;

class JsonInput extends AbstractInputProcessor {
   
    /**
     * 处理input数据
     * 
     * @access protected
     * @param string $data 数据
     * @return array
     */
    protected function handle(string $data): array {
        // 判断是否为json数据
        if($data!==false&&$data!==''&&$data!==null) {
            // 解析json数据
            $data=json_decode($data,true);
            // 判断是否解析成功
            if($data!==null)
                return $data;
        }
        return [];
    }

}