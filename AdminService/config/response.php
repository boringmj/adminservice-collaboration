<?php

namespace AdminService\config;

use AdminService\ResponseProcessor\Json;
use AdminService\ResponseProcessor\Http;

// response 相关配置
return array(
    'default'=>array(
        // 内容协商模式(仅作用于未显式指定内容类型的响应):
        // - strict: 按 Accept 协商; 有正文的 2xx 无可用类型时返回 406 text/plain
        // - fallback: 按 Accept 协商; 无可用类型时按类型表第一个具体类型回落
        // - off: 不读取 Accept, 不返回 406, 不自动追加 Vary; 未显式指定类型时按类型表第一个具体类型渲染
        // 显式 json()/html()/text()/type()/contentType() 不受本项影响
        'negotiation'=>'strict',
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