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

}
