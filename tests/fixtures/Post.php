<?php

namespace Tests\Fixtures;

use base\Model;

/**
 * 测试用文章模型(启用软删除 + 自动时间戳)
 */
class Post extends Model {

    /**
     * 数据表名
     * @var string
     */
    protected static string $table='posts';

    /**
     * 启用软删除
     * @var bool
     */
    protected static bool $softDelete=true;

    /**
     * 批量赋值白名单
     * @var array
     */
    protected array $fillable=array('title','content','status');

}
