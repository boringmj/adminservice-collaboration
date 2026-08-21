<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\Exception;

/**
 * 标量参数静默转换目标类
 */
final class ParamCastTarget {

    public function toInt(int $x): int {
        return $x;
    }

    public function toFloat(float $x): float {
        return $x;
    }

    public function toString(string $x): string {
        return $x;
    }

    public function toBool(bool $x): bool {
        return $x;
    }

    public function sum(int ...$nums): int {
        return array_sum($nums);
    }

}

/**
 * 测试容器标量参数静默转换开关(param_cast)
 *
 * 反射调用默认为严格类型,开启 param_cast 后通过 castParam 对齐 PHP 弱类型行为,
 * 关闭后参数类型不匹配将抛出异常
 */
class ParamCastTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
        App::init();
    }

    /**
     * 每个用例前确保开关为开启状态
     * @return void
     */
    protected function setUp(): void {
        App::setParamCast(true);
    }

    /**
     * 每个用例后恢复默认,避免影响其他测试类(容器为静态状态)
     * @return void
     */
    protected function tearDown(): void {
        App::setParamCast(true);
    }

    /**
     * 测试默认开启静默转换
     * @return void
     */
    public function testCastParamDefaultEnabled(): void {
        $this->assertTrue(App::getParamCast());
    }

    /**
     * 测试开关存取
     * @return void
     */
    public function testSetParamCastRoundTrip(): void {
        App::setParamCast(false);
        $this->assertFalse(App::getParamCast());
        App::setParamCast(true);
        $this->assertTrue(App::getParamCast());
    }

    /**
     * 测试数字字符串转 int(按参数名匹配)
     * @return void
     */
    public function testDigitStringToInt(): void {
        $target=new ParamCastTarget();
        $this->assertSame(2, App::exec_class_function($target,'toInt',array(
            'x'=>'2'
        )));
    }

    /**
     * 测试数字字符串转 int(按顺位参数匹配)
     * @return void
     */
    public function testDigitStringToIntPositional(): void {
        $target=new ParamCastTarget();
        $this->assertSame(2, App::exec_class_function($target,'toInt',array(0=>'2')));
    }

    /**
     * 测试数字字符串转 float
     * @return void
     */
    public function testFloatStringToFloat(): void {
        $target=new ParamCastTarget();
        $this->assertEquals(3.14, App::exec_class_function($target,'toFloat',array(
            'x'=>'3.14'
        )));
    }

    /**
     * 测试 int 转 string
     * @return void
     */
    public function testIntToString(): void {
        $target=new ParamCastTarget();
        $this->assertSame('123', App::exec_class_function($target,'toString',array(
            'x'=>123
        )));
    }

    /**
     * 测试字符串转 bool('0'为false,'1'为true)
     * @return void
     */
    public function testStringToBool(): void {
        $target=new ParamCastTarget();
        $this->assertFalse(App::exec_class_function($target,'toBool',array(
            'x'=>'0'
        )));
        $this->assertTrue(App::exec_class_function($target,'toBool',array(
            'x'=>'1'
        )));
    }

    /**
     * 测试 float 转 int 的截断行为(对齐 PHP 弱类型)
     * @return void
     */
    public function testFloatToIntTruncation(): void {
        $target=new ParamCastTarget();
        $this->assertSame(3, App::exec_class_function($target,'toInt',array(
            'x'=>3.9
        )));
    }

    /**
     * 测试 int 转 float
     * @return void
     */
    public function testIntToFloat(): void {
        $target=new ParamCastTarget();
        $this->assertSame(3.0, App::exec_class_function($target,'toFloat',array(
            'x'=>3
        )));
    }

    /**
     * 测试可变参数(int ...$nums)也执行静默转换
     * @return void
     */
    public function testDigitStringToVariadicInt(): void {
        $target=new ParamCastTarget();
        $this->assertSame(6, App::exec_class_function($target,'sum',array(
            0=>'1',1=>'2',2=>'3'
        )));
    }

    /**
     * 测试关闭静默转换后可变参数类型不匹配抛出异常
     * @return void
     */
    public function testStrictModeRejectsVariadicMismatch(): void {
        App::setParamCast(false);
        $target=new ParamCastTarget();
        $this->expectException(Exception::class);
        App::exec_class_function($target,'sum',array(0=>'1',1=>'2'));
    }

    /**
     * 测试关闭静默转换后类型不匹配抛出异常
     * @return void
     */
    public function testStrictModeRejectsTypeMismatch(): void {
        App::setParamCast(false);
        $target=new ParamCastTarget();
        $this->expectException(Exception::class);
        App::exec_class_function($target,'toInt',array('x'=>'2'));
    }

    /**
     * 测试关闭静默转换后正确的类型仍可正常调用
     * @return void
     */
    public function testStrictModeAcceptsCorrectType(): void {
        App::setParamCast(false);
        $target=new ParamCastTarget();
        $this->assertSame(2, App::exec_class_function($target,'toInt',array('x'=>2)));
        $this->assertSame('abc', App::exec_class_function($target,'toString',array(
            'x'=>'abc'
        )));
    }

    /**
     * 测试关闭静默转换后所有标量方向均被严格拒绝
     * @return void
     */
    public function testStrictModeRejectsAllScalarDirections(): void {
        App::setParamCast(false);
        $target=new ParamCastTarget();
        $cases=array(
            array($target,'toInt',array('x'=>1.5)),
            array($target,'toFloat',array('x'=>1)),
            array($target,'toString',array('x'=>123)),
            array($target,'toBool',array('x'=>1))
        );
        foreach($cases as $case) {
            try {
                App::exec_class_function($case[0],$case[1],$case[2]);
                $this->fail('期望抛出 Exception,但调用成功: '.$case[1]);
            } catch(Exception $e) {
                $this->assertInstanceOf(Exception::class,$e);
            }
        }
    }

}
