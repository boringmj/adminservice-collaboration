<?php

namespace app\demo\model;

use base\Model;

class SystemInfo extends Model {

    /**
     * 基于下面的规则自动将模型名转为数据表名 
     * 1. 小写字母后的大写字母（驼峰边界）/(?<=[a-z])(?=[A-Z])/ -> userInfo → user_Info
     * 2. 大写字母后的大写字母+小写字母（首字母缩写边界）/(?<=[A-Z])(?=[A-Z][a-z])/ → XMLParser → XML_Parser
     * 3. 字母与数字边界 /(?<=[a-zA-Z])(?=\d)|(?<=\d)(?=[a-zA-Z])/ -> user_2FA
     */

    // 模型会自动获取表名,如`SystemInfo`类等效于下面的代码
    // protected $table='system_info';
    // 上面的代码会自动添加前缀,如你的前置配置为`admin_service_`,那么上面的代码等效于下面的代码
    // protected $table='admin_service_system_info';
    // find(get)与select方法在非迭代器的模式下,支持以数组的形式访问
    // \base\Collection 类以数组访问完全只读,不支持修改和删除变量
    // \base\Model 类以数组访问支持修改,但不支持删除变量

    // =================================
    //           重要提示
    // =================================
    //
    // 目前ORM依赖主键,且主键必须为`id`
    //

    public function test() {
        return $this->where('id',1)->get();
    }

}