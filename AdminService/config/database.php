<?php

namespace AdminService\config;

// database 相关配置
return array(
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
);

?>