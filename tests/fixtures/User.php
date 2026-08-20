<?php

namespace Tests\Fixtures;

use base\Model;

/**
 * 测试用用户模型
 */
class User extends Model {

    /**
     * 数据表名
     * @var string
     */
    protected static string $table='users';

    /**
     * 批量赋值白名单
     * @var array
     */
    protected array $fillable=array('name','age','status');

}
