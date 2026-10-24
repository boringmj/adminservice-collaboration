<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\SceneValidator;
use base\Validator;

/**
 * 验证器基类测试
 *
 * - 固定 `validate()` 无参沿用数据、显式空数组清空数据的语义
 * - 固定未知场景立即失败、已知空场景仍可显式跳过校验的语义
 */
class ValidatorTest extends TestCase {

    /**
     * 创建基础验证器
     *
     * @access private
     * @param array<string,mixed> $data 待验证数据
     * @return Validator
     */
    private function validator(array $data=[]): Validator {
        return new class($data) extends Validator {
            protected array $rules=[
                'name'=>'required',
                'age'=>'required',
            ];

            protected function checkRule(string $field,string $rule,mixed $value,mixed $param=null): bool {
                if($rule==='required'&&($value===null||$value==='')) {
                    $this->error($field,['rule'=>$rule,'msg'=>$field.' 不能为空']);
                    return false;
                }
                return true;
            }

            public function sanitize(array $data): array {
                return $data;
            }
        };
    }

    /**
     * 创建场景验证器
     *
     * @access private
     * @param array<string,mixed> $data 待验证数据
     * @return SceneValidator
     */
    private function sceneValidator(array $data=[]): SceneValidator {
        return new class($data) extends SceneValidator {
            protected array $rules=[
                'name'=>'required',
                'age'=>'required',
            ];

            protected array $scenes=[
                'all'=>['name','age'],
                'name'=>['name'],
                'empty'=>[],
            ];

            protected function checkRule(string $field,string $rule,mixed $value,mixed $param=null): bool {
                if($rule==='required'&&($value===null||$value==='')) {
                    $this->error($field,['rule'=>$rule,'msg'=>$field.' 不能为空']);
                    return false;
                }
                return true;
            }

            public function sanitize(array $data): array {
                return $data;
            }
        };
    }

    /**
     * 测试无参 validate 沿用构造数据
     * @return void
     */
    public function testValidateWithoutArgsUsesConstructorData(): void {
        $validator=$this->validator(['name'=>'admin','age'=>18]);
        $this->assertTrue($validator->validate());
    }

    /**
     * 测试显式传入空数组会清空旧数据
     * @return void
     */
    public function testValidateEmptyArrayClearsPreviousData(): void {
        $validator=$this->validator(['name'=>'admin','age'=>18]);
        $this->assertTrue($validator->validate());
        $this->assertFalse($validator->validate([]));
        $this->assertArrayHasKey('name',$validator->getErrors(false));
        $this->assertArrayHasKey('age',$validator->getErrors(false));
    }

    /**
     * 测试显式传入非空数组会替换旧数据
     * @return void
     */
    public function testValidateExplicitDataReplacesPreviousData(): void {
        $validator=$this->validator(['name'=>'admin','age'=>18]);
        $this->assertFalse($validator->validate(['name'=>'operator']));
        $this->assertArrayHasKey('age',$validator->getErrors(false));
        $this->assertArrayNotHasKey('name',$validator->getErrors(false));
    }

    /**
     * 测试场景验证器无参 validate 沿用构造数据
     * @return void
     */
    public function testSceneValidateWithoutArgsUsesConstructorData(): void {
        $validator=$this->sceneValidator(['name'=>'admin','age'=>18]);
        $this->assertTrue($validator->scene('name')->validate());
    }

    /**
     * 测试场景验证器显式传入空数组会清空旧数据
     * @return void
     */
    public function testSceneValidateEmptyArrayClearsPreviousData(): void {
        $validator=$this->sceneValidator(['name'=>'admin','age'=>18]);
        $this->assertTrue($validator->scene('all')->validate());
        $this->assertFalse($validator->scene('all')->validate([]));
        $this->assertArrayHasKey('name',$validator->getErrors(false));
        $this->assertArrayHasKey('age',$validator->getErrors(false));
    }

    /**
     * 测试已知空场景仍按空规则通过
     * @return void
     */
    public function testKnownEmptyScenePasses(): void {
        $validator=$this->sceneValidator(['name'=>'','age'=>null]);
        $this->assertTrue($validator->scene('empty')->validate());
    }

    /**
     * 测试未知场景抛异常并复位场景状态
     * @return void
     */
    public function testUnknownSceneThrowsAndResetsScene(): void {
        $validator=$this->sceneValidator(['name'=>'admin','age'=>18]);
        try {
            $validator->scene('missing')->validate();
            $this->fail('预期未知场景抛出异常');
        } catch(\InvalidArgumentException $e) {
            $this->assertSame('Unknown validation scene: missing',$e->getMessage());
        }
        // 异常后场景应已复位, 下一次验证仍按全量规则执行
        $this->assertTrue($validator->validate());
    }

}
