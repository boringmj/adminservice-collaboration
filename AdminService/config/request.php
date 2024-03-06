<?php

namespace AdminService\config;

// request 相关配置
return array(
    'default'=>array(
        'type'=>'html', // 默认返回类型 html, json (default: html) 
        'json'=>array( // json类型返回的默认配置
            'code'=>1, // 默认返回code, default: 1
            'msg'=>'success', // 默认返回msg, default: success
            /**
             * [optional] 
             * Bitmask consisting of 
             * JSON_HEX_QUOT,
             * JSON_HEX_TAG,
             * JSON_HEX_AMP,
             * JSON_HEX_APOS,
             * JSON_NUMERIC_CHECK,
             * JSON_PRETTY_PRINT,
             * JSON_UNESCAPED_SLASHES,
             * JSON_FORCE_OBJECT,
             * JSON_UNESCAPED_UNICODE
             * JSON_THROW_ON_ERROR The behaviour of these constants is described on the JSON constants page.
             * default: 0 (No options are set.)
             */
            'flag'=>0
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