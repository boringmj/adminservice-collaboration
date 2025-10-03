<?php

namespace app\demo\controller;

use base\Controller;
use AdminService\Log;
use AdminService\Autowire\AutowireSetter;
use AdminService\Autowire\AutowireProperty;

class Autowire extends Controller {

    // 通过属性的方式自动注入
    #[AutowireProperty]
    private Log $property_log;

    private Log $setter_log;

    // 通过方法的形参的方式自动注入(参数必需是类名或接口名且只能有一个)
    #[AutowireSetter]
    private function setter_log(Log $log) {
        // 这里需要自己写存储逻辑
        $this->setter_log=$log;
    }

    /**
     * 验证方法,用于验证自动注入是否正常
     * @return string
     */
    public function index(): string {
        $this->property_log->write("Property Log");
        $this->setter_log->write("Setter Log");
        return "Ok!";
    }

}