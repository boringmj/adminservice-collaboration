<?php

namespace AdminService\config;

use \PDO;
use AdminService\sql\Mysql;

// database 相关配置
return array(
    'default'=>array(
        'type'=>'mysql', // default: mysql
        'host'=>'localhost', // 数据库地址 default: localhost
        'port'=>3306, // 数据库端口 default: 3306
        'user'=>'', // 数据库用户名
        'password'=>'', // 数据库密码
        'dbname'=>'', // 数据库名
        'charset'=>'utf8mb4', // 数据库编码 default: utf8, utf8mb4 需要mysql5.5.3及以上且数据库、表和字段都支持
        'prefix'=>'', // 数据表前缀 default: ''
        'options'=>array( // 数据库连接选项
            PDO::ATTR_STRINGIFY_FETCHES=>false, // 是否将数值字段强制转换为字符串 (false 保持原生类型)
            PDO::ATTR_EMULATE_PREPARES=>true, // 是否使用PDO模拟预处理 (true性能可能会更好,但失去原生类型检查/安全保障)
            PDO::ATTR_PERSISTENT=>true, // 是否开启持久连接 (减少建连开销,但可能引发连接状态污染)
        )
    ),
    'rule'=>array(
        'fields'=>'/^[A-Za-z][A-Za-z0-9_]{1,31}$/', // 数据库字段名规则
        'table'=>'/^[A-Za-z][A-Za-z0-9_]{1,63}$/' // 数据库表名规则
    ),
    'support_type'=>array(
        'mysql'=>Mysql::class // Mysql类型的数据库支持
    )
);