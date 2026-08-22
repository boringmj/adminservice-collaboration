<?php

namespace AdminService\config;

use PDO;
use base\Database\Sql\Compiler\MysqlCompiler;
use base\Database\Sql\Dialect\MysqlDialect;

// database 相关配置
return array(
    // 多个命名连接, 通过 Db::fromConfig('连接名') 切换
    'connections'=>array(
        'default'=>array(
            'type'=>'mysql', // default: mysql (仅用于构建 PDO DSN, 方言/编译器由下面两个类决定)
            'host'=>'localhost', // 数据库地址 default: localhost
            'port'=>3306, // 数据库端口 default: 3306
            'user'=>'', // 数据库用户名
            'password'=>'', // 数据库密码
            'dbname'=>'', // 数据库名
            'charset'=>'utf8mb4', // 数据库编码 default: utf8, utf8mb4 需要mysql5.5.3及以上且数据库、表和字段都支持
            'prefix'=>'', // 数据表前缀 default: '' (由新 DBAL 编译期统一添加)
            // 方言类(可选, 未指定则按类型使用默认 MySQL; 自定义方言需实现 base\Database\Sql\Dialect\DialectInterface)
            'dialect'=>MysqlDialect::class,
            // 编译器类(可选, 未指定默认 MySQL; 自定义编译器需实现 base\Database\Sql\Compiler\SqlCompilerInterface)
            // 例: 接入 PostgreSQL 时改为 'compiler'=>PgsqlCompiler::class
            'compiler'=>MysqlCompiler::class,
            // 连接池配置(可选)
            'pool'=>array(
                'max_idle'=>20, // 闲置连接上限(归还时超过则丢弃, 控制物理连接数) default: 20
            ),
            'options'=>array( // 数据库连接选项
                PDO::ATTR_STRINGIFY_FETCHES=>false, // 是否将数值字段强制转换为字符串 (false 保持原生类型)
                PDO::ATTR_EMULATE_PREPARES=>true, // 是否使用PDO模拟预处理 (true性能可能会更好,但失去原生类型检查/安全保障)
                PDO::ATTR_PERSISTENT=>true, // 是否开启持久连接 (减少建连开销,但可能引发连接状态污染)
            )
        ),
        // 示例: 第二个命名连接, 通过 Db::fromConfig('log') 使用
        // 每个连接可独立配置闲置上限: 低访问量库调小, 高访问量库调大
        // 'log'=>array(
        //     'dbname'=>'admin_service_log',
        //     'pool'=>array(
        //         'max_idle'=>3, // 低访问量库: 只保留少量闲置连接
        //     ),
        // ),
    ),
    // 中间件(可选, 按声明顺序执行)
    // 每个中间件必须实现 base\Database\Middleware\QueryMiddlewareInterface, 支持两种写法:
    // 1. 类名: 由框架容器 App::get 解析(可依赖注入)
    //    'middlewares'=>array(\App\Middleware\LogQuery::class),
    // 2. 已实例化对象: 原样使用
    //    'middlewares'=>array(new \App\Middleware\LogQuery()),
    // 混排亦可, 无效中间件会在构建数据库入口时抛 ConfigException
    'middlewares'=>array()
);
