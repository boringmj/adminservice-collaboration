<?php

namespace app\demo\model;

use base\Orm\Model;
use base\Orm\BelongsTo;

/**
 * 示例订单模型
 *
 * @property-read \app\demo\model\User|null $user 订单的用户
 */
class Order extends Model {

    /**
     * 数据表名
     * @var string
     */
    protected static string $table='orders';

    /**
     * 批量赋值白名单
     * @var array
     */
    protected array $fillable=array('user_id','order_no','amount','status');

    /**
     * 多对一关系(订单属于用户)
     *
     * @access public
     * @return BelongsTo
     */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

}
