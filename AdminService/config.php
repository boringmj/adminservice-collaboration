<?php

namespace AdminService;

return array(
    // app 相关配置
    'app'=>array(
        'path'=>__DIR__.'/app',
    ),

    // route 相关配置
    'route'=>array(
        'default'=>array(
            'app'=>'Index',
            'controller'=>'Index',
            'method'=>'index'
        ),
        'params'=>array(
            'toget'=>array(
                'model'=>'auto' // value, list, value-list, list-value (default: list-value)
            ),
            'rule'=>array(
                'app'=>'/^[A-Z_][a-zA-Z0-9_\-]+$/',
                'controller'=>'/^[A-Z_][a-zA-Z0-9_\-]+$/',
                'method'=>'/^[a-z_][a-zA-Z0-9_\-]+$/',
                'get'=>'/^[a-zA-Z0-9_]+$/'
            )
        )
    ),

    // data 相关配置
    'data'=>array(
        'path'=>__DIR__.'/data', // 该目录需要可写权限
        'ext_name'=>'.data.json', // 文件扩展名
        'dir_mode'=>0644, // 目录权限(Windows下无效)
        'rule'=>array(
            'file'=>'/^[a-zA-Z0-9_\-]+$/',
            'key'=>'/^[a-zA-Z0-9_\-]+$/'
        )
    ),

    // function 相关配置
    'function'=>array(
        'path'=>__DIR__.'/common',
        'loader'=>array(
            'uuid'
        )
    ),

    // request 相关配置
    'request'=>array(
        'default'=>array( // 该项允许缺省
            'type'=>'html', //html, json (default: html) 
            'json'=>array(
                'code'=>1, // default: 1
                'msg'=>'success' // default: success
            )
        ),
        'html'=>array(
            'header'=>array(
                'Content-Type'=>'text/html;charset=utf-8'
            )
        ),
        'json'=>array(
            'header'=>array(
                'Content-Type'=>'application/json;charset=utf-8'
            )
        )
    ),

    // cookie
    'cookie'=>array(    // 该项允许缺省
        'prefix'=>'', // 前缀
        'expire'=>3600, // 过期时间 default: 3600
        'path'=>'', // 路径
        'domain'=>'', // 域名 default: ''
        'secure'=>false, // 是否仅仅通过安全的 HTTPS 连接传给客户端 default: false
        'httponly'=>false // 是否仅可通过 HTTP 协议访问 default: false
    )
);

?>