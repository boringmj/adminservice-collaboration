<?php

namespace AdminService\config;

use base\Request;
use AdminService\JsonInput;
use AdminService\NativeSession;

// request 相关配置
return array(
    'default'=>array(
        'upload'=>array(
            'max_size'=>104857600, // 最大上传大小 (字节) 默认100MB
            'name_rule'=>'/[^a-zA-Z\.0-9_\-]/', // 文件名过滤规则 (default: /[^a-zA-Z0-9_\-]/)
            'ext_rule'=>'/[^a-z0-9]/', // 文件扩展名过滤规则 (default: /[^a-z0-9]/)
            'hash'=>array(
                'algo'=>'sha1', // 文件哈希算法 (default: sha1)
                'max_size'=>104857600 // 最大可计算哈希文件大小 (字节) (default: 100MB)
            ),
            'save'=>array(
                'dir'=>__DIR__.'/../uploads', // 上传目录 (default: ../uploads)
                'mode'=>0644 // 自动创建的目录权限(Windows下无效)
            )
        ),
        'input'=>array( // Input解析器(键名为Content-Type的值,统一小写)
            'application/json'=>JsonInput::class // JSON格式解析器
        ),
        'param'=>array(
            'input'=>Request::POST_PARAM, // 将input参数合并到何处,`0`为不合并,默认为`0`
            'order'=>'CGP' // 参数处理顺序,默认为CGP,即Cookie<Get<Post,目前仅支持Cookie,Get,Post
        ),
        'session'=>array(
            'enable'=>false, // 是否启用Session
            'class'=>NativeSession::class, // Session处理类
        )
    )
);