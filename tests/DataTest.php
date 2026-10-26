<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Data;

/**
 * Data 容器测试
 *
 * - 显式 null 是一个值，不是“键不存在”
 */
class DataTest extends TestCase {

    /**
     * get() 必须保留显式 null
     * @return void
     */
    public function testGetKeepsExplicitNull(): void {
        $data=new Data(array('a'=>null));
        $this->assertNull($data->get('a','DFLT'));
    }

    /**
     * has() 对显式 null 必须返回 true
     * @return void
     */
    public function testHasKeepsExplicitNull(): void {
        $data=new Data(array('a'=>null));
        $this->assertTrue($data->has('a'));
    }

    /**
     * 缺失键仍回落默认值
     * @return void
     */
    public function testMissingKeyUsesDefault(): void {
        $data=new Data(array());
        $this->assertSame('DFLT',$data->get('a','DFLT'));
        $this->assertFalse($data->has('a'));
    }

    /**
     * 大小写不敏感模式下，显式 null 仍可被识别
     * @return void
     */
    public function testCaseInsensitiveNullKey(): void {
        $data=(new Data(array('A'=>null)))->setCaseSensitive(false)->resetKey();
        $this->assertNull($data->get('a','DFLT'));
        $this->assertTrue($data->has('a'));
    }

    /**
     * 大小写不敏感模式下，save() 写入的键也必须归一
     *
     * @return void
     */
    public function testSaveNormalizesCaseInsensitiveKeys(): void {
        $data=(new Data(array('A'=>1)))->setCaseSensitive(false)->resetKey();
        $data->save(array('B'=>2));
        $this->assertSame(2,$data->get('b','DFLT'));
        $this->assertTrue($data->has('b'));
        $this->assertSame(array('a','b'),array_keys($data->all()));
    }

    /**
     * save() 对数字键保持追加语义, 字符串键覆盖
     *
     * @return void
     */
    public function testSaveAppendsNumericKeysAndOverwritesStringKeys(): void {
        $data=new Data(array('a'=>1,0=>'x',1=>'y'));
        $data->save(array('a'=>2,0=>'z'));
        $this->assertSame(
            array('a'=>2,0=>'x',1=>'y',2=>'z'),
            $data->all()
        );
    }

    /**
     * batchSet() 对数字键保持按键覆盖
     *
     * @return void
     */
    public function testBatchSetOverwritesNumericKeys(): void {
        $data=new Data(array(0=>'a',1=>'b'));
        $data->batchSet(array(0=>'c',2=>'d'));
        $this->assertSame(array(0=>'c',1=>'b',2=>'d'),$data->all());
    }

    /**
     * save() 保留显式 null
     *
     * @return void
     */
    public function testSaveKeepsExplicitNull(): void {
        $data=new Data(array('a'=>1));
        $data->save(array('a'=>null));
        $this->assertTrue($data->has('a'));
        $this->assertNull($data->get('a','DFLT'));
    }

}
