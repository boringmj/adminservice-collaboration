<?php

namespace Tests\Fixtures;

use base\Orm\Model;

/**
 * 测试用资料模型(无软删除)
 */
class Profile extends Model {

    /**
     * 数据表名
     * @var string
     */
    protected static string $table='profiles';

    /**
     * 批量赋值白名单
     * @var array
     */
    protected array $fillable=array('user_id','bio');

}
