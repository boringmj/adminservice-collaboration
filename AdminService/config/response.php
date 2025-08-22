<?php

namespace AdminService\config;

use AdminService\ResponseProcessor\Json;
use AdminService\ResponseProcessor\Http;

// response 相关配置
return array(
    'default'=>array(
        'type'=>array( // 如果不指定返回方式,默认使用第一个
            '*/*'=>array(
                'class'=>Http::class
            ),
            'text/html'=>array(
                'class'=>Http::class,
                'headers'=>array(
                    'Content-Type'=>'text/html; charset=utf-8'
                )
            ),
            'text/plain'=>array(
                'class'=>Http::class,
                'headers'=>array(
                    'Content-Type'=>'text/plain; charset=utf-8'
                )
            ),
            'application/json'=>array(
                'class'=>Json::class,
                'flag'=>JSON_UNESCAPED_UNICODE,
                'headers'=>array(
                    'Content-Type'=>'application/json; charset=utf-8'
                )
            )
        )
    )
);