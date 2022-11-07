<?php

namespace AdminService;

/**
 * 配置规则
 * 
 * 1. 注释项明确标明有默认值的,可以直接删除,否则必须保留
 */

return array(
    // app 相关配置
    'app'=>array(
        'path'=>__DIR__.'/app',
    ),

    // route 相关配置
    'route'=>array(
        'default'=>array(
            'app'=>'index', // 默认应用
            'controller'=>'Index', // 默认控制器
            'method'=>'index' // 默认方法
        ),
        'params'=>array(
            'toget'=>array(
                'model'=>'list-value' // value, list, value-list, list-value (default: list-value)
            ),
            'rule'=>array(
                'app'=>'/^[a-z_][a-zA-Z0-9_\-]+$/', // 应用名规则
                'controller'=>'/^[A-Z_][a-zA-Z0-9_\-]+$/', // 控制器名规则
                'method'=>'/^[a-z_][a-zA-Z0-9_\-]+$/', // 方法名规则
                'get'=>'/^[a-zA-Z0-9_]+$/' // get参数名规则
            )
        )
    ),

    // data 相关配置
    'data'=>array(
        'path'=>__DIR__.'/data', // 该目录需要可写权限
        'ext_name'=>'.data.json', // 文件扩展名
        'dir_mode'=>0644, // 目录权限(Windows下无效)
        'rule'=>array(
            'file'=>'/^[a-zA-Z0-9_\-]+$/', // 文件名规则(暂未生效)
            'key'=>'/^[a-zA-Z0-9_\-]+$/' // 数据的键名规则(暂未生效)
        )
    ),

    // function 相关配置
    'function'=>array(
        'path'=>__DIR__.'/common', // 公共函数目录
        'loader'=>array( // 需要自动加载的函数文件名(不含扩展名)
            'uuid', // uuid函数
            'helper', // 通用助手函数
            'controller_helper' // 控制器助手函数
        )
    ),

    // request 相关配置
    'request'=>array(
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
    ),

    // cookie 相关配置
    'cookie'=>array(
        'prefix'=>'', // 前缀 default: ''
        'expire'=>3600, // 过期时间 default: 3600
        'path'=>'', // 路径 default: ''
        'domain'=>'', // 域名 default: ''
        'secure'=>false, // 是否仅仅通过安全的 HTTPS 连接传给客户端 default: false
        'httponly'=>false // 是否仅可通过 HTTP 协议访问 default: false
    ),

    // database 相关配置
    'database'=>array(
        'default'=>array(
            'type'=>'mysql', // default: mysql
            'host'=>'localhost', // 数据库地址 default: localhost
            'port'=>3306, // 数据库端口 default: 3306
            'user'=>'', // 数据库用户名
            'password'=>'', // 数据库密码
            'dbname'=>'', // 数据库名
            'charset'=>'utf8', // 数据库编码 default: utf8
            'prefix'=>'' // 数据表前缀 default: ''
        ),
        'rule'=>array(
            'fields'=>'/^[A-Za-z][A-Za-z0-9_]{1,31}$/', // 数据库字段名规则
            'table'=>'/^[A-Za-z][A-Za-z0-9_]{1,63}$/' // 数据库表名规则
        ),
        'support_type'=>array(
            'mysql'=>\AdminService\sql\Mysql::class // Mysql类型的数据库支持
        )
    )

);

?>