<?php

namespace AdminService\config;

use AdminService\ResponseProcessor\Json;
use AdminService\ResponseProcessor\Http;

// response 相关配置
return array(
    'default'=>array(
        'negotiation'=>'strict', // 协商失败: strict=406, fallback=按默认类型
        'type'=>array( // 内容类型登记表; */* 仅作渲染分派桶, 不参与候选选择
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