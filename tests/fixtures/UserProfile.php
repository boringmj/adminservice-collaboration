<?php

namespace Tests\Fixtures;

use AdminService\Autowire\AutowireMethod;

/**
 * 模拟用户资料类(含生命周期方法注入)
 */
class UserProfile {

    /**
     * 用户名称(由 init 生命周期方法注入)
     * @var UserName|null
     */
    public $name;

    /**
     * 用户状态(由 init 生命周期方法注入)
     * @var AbstractStatus|null
     */
    public $status;

    /**
     * 是否已执行生命周期方法
     * @var bool
     */
    public $booted=false;

    /**
     * 生命周期方法: 构建后自动注入参数并调用
     *
     * @access public
     * @param UserName $name 用户名称
     * @param AbstractStatus $status 用户状态
     * @return void
     */
    #[AutowireMethod]
    public function init(UserName $name,AbstractStatus $status): void {
        $this->name=$name;
        $this->status=$status;
        $this->booted=true;
    }

}
