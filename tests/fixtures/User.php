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

    /**
     * 多对多关系(用户的角色)
     *
     * - 默认中间表 role_user(user_id, role_id)
     *
     * @access public
     * @return \base\Orm\BelongsToMany
     */
    public function roles(): \base\Orm\BelongsToMany {
        return $this->belongsToMany(Role::class);
    }

    /**
     * 一对多关系(用户的已发布文章, 带链式条件与排序)
     *
     * @access public
     * @return \base\Orm\HasMany
     */
    public function publishedPosts(): \base\Orm\HasMany {
        return $this->hasMany(Post::class)->where('status',1)->orderBy('id','DESC');
    }

    /**
     * 多对多关系(用户的启用角色, 带链式条件)
     *
     * @access public
     * @return \base\Orm\BelongsToMany
     */
    public function activeRoles(): \base\Orm\BelongsToMany {
        return $this->belongsToMany(Role::class)->where('status',1);
    }

    /**
     * 一对多关系(用户的最新文章, 带数量限制)
     *
     * @access public
     * @return \base\Orm\HasMany
     */
    public function latestPosts(): \base\Orm\HasMany {
        return $this->hasMany(Post::class)->limit(3);
    }

}
