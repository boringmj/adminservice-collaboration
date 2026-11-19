<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\FormValidator;
use stdClass;

use function error_reporting;
use function restore_error_handler;
use function set_error_handler;

/**
 * FormValidator 边界测试
 *
 * - 标量规则遇到数组/对象时应产生校验错误，而不是 TypeError/警告或静默通过
 * - 整数 0、in 宽松比较、between 参数、非法正则应得到确定结果
 */
class FormValidatorTest extends TestCase {

    /**
     * date 规则不能把数组传给 strtotime
     * @return void
     */
    public function testDateRuleRejectsArrayValue(): void {
        $validator=new FormValidator(
            array('field'=>array('x')),
            array('field'=>'date')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * min/max/min_length/max_length 缺区间参数时报区间参数无效
     * @return void
     */
    public function testRangeRulesWithoutParamReportInvalidRange(): void {
        foreach(array('min','max','min_length','max_length') as $rule) {
            $validator=new FormValidator(
                array('field'=>10),
                array('field'=>$rule)
            );
            $this->assertFalse($validator->validate(),$rule.' 缺区间参数应报错');
            $errors=$validator->getErrors();
            $this->assertArrayHasKey('field',$errors,$rule);
            $this->assertSame('field 的区间参数无效',$errors['field'][0],$rule);
        }
    }

    /**
     * 可选字段的 null 值对所有格式规则一致通过
     * @return void
     */
    public function testNullValuePassesAllFormatRulesConsistently(): void {
        $rules=array('email','url','numeric','integer','date','in:a,b','not_in:a,b','phone','ip','json');
        foreach($rules as $rule) {
            $validator=new FormValidator(
                array('field'=>null),
                array('field'=>$rule)
            );
            $this->assertTrue($validator->validate(),$rule.' 对可选字段的 null 值应通过');
            $this->assertSame(array(),$validator->getErrors(),$rule);
        }
    }

    /**
     * required 对 null 值仍然失败(不因 null 语义统一而放宽)
     * @return void
     */
    public function testRequiredStillFailsOnNull(): void {
        $validator=new FormValidator(
            array('field'=>null),
            array('field'=>'required|email')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * 标量规则名大小写不敏感, 混合大小写也不能绕过数组保护
     * @return void
     */
    public function testMixedCaseDateRuleRejectsArrayValue(): void {
        $validator=new FormValidator(
            array('field'=>array('x')),
            array('field'=>'Date')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * 混合大小写的 between 规则同样不能对数组静默通过
     * @return void
     */
    public function testMixedCaseBetweenRuleRejectsArrayValue(): void {
        $validator=new FormValidator(
            array('field'=>array('x')),
            array('field'=>'Between:1,3')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * regex 规则不能把数组转成字符串后静默通过
     * @return void
     */
    public function testRegexRuleRejectsArrayValue(): void {
        $validator=new FormValidator(
            array('field'=>array('x')),
            array('field'=>'regex:/.*/')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * between 规则不能对数组静默通过
     * @return void
     */
    public function testBetweenRuleRejectsArrayValue(): void {
        $validator=new FormValidator(
            array('field'=>array('x')),
            array('field'=>'between:1,3')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * min 规则不能对数组静默通过
     * @return void
     */
    public function testMinRuleRejectsArrayValue(): void {
        $validator=new FormValidator(
            array('field'=>array('x')),
            array('field'=>'min:5')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * 对象值进入标量规则时也必须产生校验错误
     * @return void
     */
    public function testObjectValueRejectedByScalarRule(): void {
        $value=new stdClass();
        $value->x=1;
        $validator=new FormValidator(
            array('field'=>$value),
            array('field'=>'date')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * integer 规则应接受整数 0，而不是把 0 当作 false
     * @return void
     */
    public function testIntegerRuleAcceptsZero(): void {
        $validator=new FormValidator(
            array('field'=>0),
            array('field'=>'integer')
        );
        $this->assertTrue($validator->validate());

        $validator=new FormValidator(
            array('field'=>'0'),
            array('field'=>'integer')
        );
        $this->assertTrue($validator->validate());
    }

    /**
     * in 规则不应把布尔值按宽松比较当成选项 1
     * @return void
     */
    public function testInRuleRejectsBooleanValue(): void {
        $validator=new FormValidator(
            array('field'=>true),
            array('field'=>'in:1,2')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * in 规则仍应接受与选项等值的整数
     * @return void
     */
    public function testInRuleAcceptsNumericValue(): void {
        $validator=new FormValidator(
            array('field'=>1),
            array('field'=>'in:1,2')
        );
        $this->assertTrue($validator->validate());
    }

    /**
     * not_in 规则不应把布尔值按宽松比较当成选项 1
     * @return void
     */
    public function testNotInRuleRejectsBooleanValue(): void {
        $validator=new FormValidator(
            array('field'=>true),
            array('field'=>'not_in:1,2')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * between 规则仍应接受区间内的值
     * @return void
     */
    public function testBetweenRuleAcceptsValueInRange(): void {
        $validator=new FormValidator(
            array('field'=>2),
            array('field'=>'between:1,3')
        );
        $this->assertTrue($validator->validate());
    }

    /**
     * between 缺少区间参数时应产生校验错误
     * @return void
     */
    public function testBetweenRuleRejectsMissingRange(): void {
        $validator=new FormValidator(
            array('field'=>''),
            array('field'=>'between')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * between 区间参数不完整时应产生校验错误
     * @return void
     */
    public function testBetweenRuleRejectsIncompleteRange(): void {
        $validator=new FormValidator(
            array('field'=>5),
            array('field'=>'between:1')
        );
        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('field',$validator->getErrors());
    }

    /**
     * 非法正则应产生校验错误，而不是 PHP 警告
     * @return void
     */
    public function testInvalidRegexDoesNotEmitWarning(): void {
        $warnings=array();
        set_error_handler(function(int $type,string $message) use (&$warnings): bool {
            if(!(error_reporting()&$type))
                return false;
            $warnings[]=$message;
            return true;
        });
        try {
            $validator=new FormValidator(
                array('field'=>'abc'),
                array('field'=>'regex:/[/')
            );
            $valid=$validator->validate();
        } finally {
            restore_error_handler();
        }
        $this->assertFalse($valid);
        $this->assertSame(array(),$warnings);
    }

    /**
     * array/required 规则仍应接受非空数组
     * @return void
     */
    public function testRequiredArrayRuleStillAcceptsArray(): void {
        $validator=new FormValidator(
            array('field'=>array('x')),
            array('field'=>'required|array')
        );
        $this->assertTrue($validator->validate());
        $this->assertSame(array(),$validator->getErrors());
    }

    /**
     * email/url 规则的字符串 "0" 参与校验, 不因假值短路通过
     * @return void
     */
    public function testStringZeroIsCheckedByEmailAndUrlRules(): void {
        foreach(array('email','url') as $rule) {
            $validator=new FormValidator(
                array('field'=>'0'),
                array('field'=>$rule)
            );
            $this->assertFalse($validator->validate(),$rule.' 对字符串 "0" 应产生校验错误');
            $this->assertArrayHasKey('field',$validator->getErrors(),$rule);
        }
        // 空串仍按可选字段通过
        foreach(array('email','url') as $rule) {
            $validator=new FormValidator(
                array('field'=>''),
                array('field'=>$rule)
            );
            $this->assertTrue($validator->validate(),$rule.' 对空串应通过');
            $this->assertSame(array(),$validator->getErrors(),$rule);
        }
        // 合法值通过
        $this->assertTrue((new FormValidator(
            array('field'=>'a@b.com'),
            array('field'=>'email')
        ))->validate());
        $this->assertTrue((new FormValidator(
            array('field'=>'https://example.com'),
            array('field'=>'url')
        ))->validate());
    }

}
