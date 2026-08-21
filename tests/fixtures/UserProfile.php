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
     * 用户名称(由 boot 显式注入, 参数无类型)
     * @var UserName|null
     */
    public $explicit;

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

    /**
     * 显式注入的生命周期方法(单参数无类型, 指定 name)
     *
     * @access public
     * @param mixed $name 用户名称(由 name 指定注入)
     * @return void
     */
    #[AutowireMethod(UserName::class)]
    public function boot($name): void {
        $this->explicit=$name;
    }

}
