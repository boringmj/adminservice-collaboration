<?php

namespace Tests\Fixtures;

use base\Orm\Model;

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

    /**
     * 一对多关系(用户的文章)
     *
     * @access public
     * @return \base\Orm\HasMany
     */
    public function posts(): \base\Orm\HasMany {
        return $this->hasMany(Post::class);
    }

    /**
     * 一对一关系(用户的资料)
     *
     * @access public
     * @return \base\Orm\HasOne
     */
    public function profile(): \base\Orm\HasOne {
        return $this->hasOne(Profile::class);
    }

}
