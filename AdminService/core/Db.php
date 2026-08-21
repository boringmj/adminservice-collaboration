<?php

namespace AdminService;

use AdminService\Database\DbFacade;

/**
 * 数据库门面
 *
 * - 裸查询的用户入口: use AdminService\Db;
 *   Db::connection()->table('users')->where('id',1)->get()   默认连接
 *   Db::connection('log')->table('users')->get()              指定命名连接
 * - 继承 DbFacade 提供 table()/raw()/transaction(); connection() 返回绑定连接的本类实例
 */
final class Db extends DbFacade {

    /**
     * 选择连接, 返回绑定该连接的门面实例
     *
     * @access public
     * @param string $name 连接名(默认 default)
     * @return static
     */
    public static function connection(string $name='default'): static {
        return new static($name);
    }

}
