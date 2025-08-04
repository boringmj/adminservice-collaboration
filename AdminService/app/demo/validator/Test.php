<?php

namespace app\demo\validator;

use AdminService\FormValidator;

class Test extends FormValidator {

    /**
     * 支持的验证方式有
     * required 不可为空
     * email 是否是一个有效的邮箱地址
     * min:<int> 最小值
     * max:<int> 最大值
     * min_length:<int> 最小长度
     * max_length:<int> 最大长度
     * numeric 是否为数字
     * integer 是否为整型
     * url 是否是一个有效的URL
     * regex:<regex> 是否匹配正则表达式
     * in:<int|string>,[... int|string] 包含
     * not_in:<int|string>,[... int|string] 不包含
     * same:<field> 与另一字段相同
     * different:<field> 与另一个字段不同
     * date:<date> 是否是一个日期
     * after:<date> 是否在日期之后
     * before:<date> 是否在日期之前
     * between:<start_date>,<end_start> 是否在两个日期之间
     * not_betwee:<start_date>,<end_start> 是否不在两个日期之间
     * ip 是否是一个有效的IP
     * phone 是否是有效的手机号
     * json 是否是有个有效json
     * array 是否是一个数组
     * sensitive:[type] 显式标注字段为敏感数据
     * 
     * 支持的脱敏方式有
     * only_first 仅显示第一位
     * only_last 仅显示最后一位
     * hide 使用`***`隐藏数据 (默认)
     * replace 按位数替换为`*`
     * hint 替换为`**已脱敏**`
     */

    /**
     * 验证规则集
     * 
     * @return array
     */
    public function rules(): array {
        return [
            'name'=>'required|min_length:6|max_length:15|regex:/^[a-zA-Z]+$/',
            'pass'=>'same:name|regex:/^.{6,32}$/|sensitive:replace',
            'int'=>'in:1,2,3|is_value:1',
        ];
    }

    /**
     * 所有场景字段映射
     * 
     * @return array
     */
    protected function scenes(): array {
        return [
            'all'=>[ // all场景,验证所有字段
                'name',
                'pass',
                'int'
            ],
            'name'=>[ // name场景,仅验证name字段
                'name'
            ]
        ];
    }
    
    /**
     * 展示自定义规则
     * 
     * @param string $field 字段名称
     * @param mixed $value 字段值
     * @param mixed $param 验证参数
     */
    public function validateIsValue(string $field,$value,$param) {
        if($value!=$param)
            return $this->addError($field,'is_value',$param,'{field}的值必须等于{param}');
        return true;
    }

}
