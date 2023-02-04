<?php

namespace AdminService\config;

// request 相关配置
return array(
    'default'=>array(
        'type'=>'html', // 默认返回类型 html, json (default: html) 
        'json'=>array( // json类型返回的默认配置
            'code'=>1, // default: 1
            'msg'=>'success' // default: success
        )
    ),
    'html'=>array(
        'header'=>array( // html类型返回的header头
            'Content-Type'=>'text/html;charset=utf-8'
        )
    ),
    'json'=>array(
        'header'=>array( // json类型返回的header头
            'Content-Type'=>'application/json;charset=utf-8'
        )
    )
);

?>