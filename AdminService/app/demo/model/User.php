<?php

namespace app\demo\model;

use base\Orm\Model;
use base\Orm\HasMany;
use base\Orm\BelongsToMany;

/**
 * 示例用户模型(表前缀 admin_service_ 由编译器统一添加)
 *
 * @property-read \base\Orm\ModelCollection $orders 用户的订单
 * @property-read \base\Orm\ModelCollection $roles 用户的角色
 */
class User extends Model {

    /**
     * 数据表名
     * @var string
     */
    protected static string $table='users';

    /**
     * 启用软删除
     * @var bool
     */
    protected static bool $softDelete=true;

    /**
     * 批量赋值白名单
     * @var array
     */
    protected array $fillable=array('name','age','status');

    /**
     * 一对多关系(用户的订单)
     *
     * @access public
     * @return HasMany
     */
    public function orders(): HasMany {
        return $this->hasMany(Order::class);
    }

    /**
     * 多对多关系(用户的角色)
     *
     * - 默认中间表 role_user(user_id, role_id)
     *
     * @access public
     * @return BelongsToMany
     */
    public function roles(): BelongsToMany {
        return $this->belongsToMany(Role::class);
    }

}
