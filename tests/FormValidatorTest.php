<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\FormValidator;
use stdClass;

/**
 * FormValidator 数组值边界测试
 *
 * - 标量规则遇到数组时应产生校验错误，而不是 TypeError/警告或静默通过
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

}
