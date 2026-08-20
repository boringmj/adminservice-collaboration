<?php

namespace app\demo\model;

use base\Orm\Model;
use base\Orm\BelongsToMany;

/**
 * 示例角色模型(表前缀 admin_service_ 由编译器统一添加)
 *
 * @property-read \base\Orm\ModelCollection $users 拥有该角色的用户
 */
class Role extends Model {

    /**
     * 数据表名
     * @var string
     */
    protected static string $table='roles';

    /**
     * 批量赋值白名单
     * @var array
     */
    protected array $fillable=array('name','status');

    /**
     * 多对多关系(拥有该角色的用户)
     *
     * @access public
     * @return BelongsToMany
     */
    public function users(): BelongsToMany {
        return $this->belongsToMany(User::class);
    }

}
