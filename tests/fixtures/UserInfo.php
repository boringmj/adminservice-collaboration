<?php

namespace Tests\Fixtures;

use AdminService\DynamicProxy;
use AdminService\Autowire\AutowireProperty;

/**
 * 模拟用户信息类
 * @package Tests\Fixtures
 */
class UserInfo {

    /**
     * 用户名称
     * @var UserName
     */
    #[AutowireProperty]
    public UserName $name;

    /**
     * 用户状态
     * @var AbstractStatus
     */
    #[AutowireProperty(AbstractStatus::class)]
    public $status;

    /**
     * 用户ID
     * @var DynamicProxy<UserId>
     */
    #[AutowireProperty(UserId::class,true)]
    public DynamicProxy $id;

}