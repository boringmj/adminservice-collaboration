<?php

namespace Tests\Fixtures;

use base\Orm\Model;

/**
 * 测试用角色模型(无软删除, 用于多对多关系)
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
     * 多对多关系(角色的用户)
     *
     * - 默认中间表 role_user(user_id, role_id)
     *
     * @access public
     * @return \base\Orm\BelongsToMany
     */
    public function users(): \base\Orm\BelongsToMany {
        return $this->belongsToMany(User::class);
    }

}
