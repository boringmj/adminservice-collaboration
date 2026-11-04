<?php

namespace AdminService;

use base\ConfigInterface;
use base\Cookie as BaseCookie;

use function is_array;
use function setcookie;
use function time;

/**
 * Cookie 下发服务
 *
 * - 每个请求一个实例，配置通过构造注入
 * - 由 Response 在发送阶段调用，不读取门面或全局容器
 */
final class Cookie extends BaseCookie {

    /**
     * 配置
     * @var ConfigInterface
     */
    private ConfigInterface $config;

    /**
     * 构造方法
     *
     * @access public
     * @param ConfigInterface $config 配置
     */
    public function __construct(ConfigInterface $config) {
        $this->config=$config;
    }

    /**
     * 通过数组的方式设置cookie
     *
     * @access public
     * @param array<string,mixed> $data 数据
     * @return void
     */
    public function setByArray(array $data): void {
        foreach($data as $key=>$value)
            if(is_array($value))
                $this->set(
                    $key,
                    $value['value']??'',
                    $value['expire']??null,
                    $value['path']??null,
                    $value['domain']??null,
                    $value['secure']??null,
                    $value['httponly']??null
                );
            else
                $this->set($key,$value);
    }

    /**
     * 设置Cookie
     *
     * @access public
     * @param string $name 名称
     * @param mixed $value 值
     * @param int|null $expire 过期时间
     * @param string|null $path 路径
     * @param string|null $domain 域名
     * @param bool|null $secure 是否仅https
     * @param bool|null $httponly 是否仅http
     * @return void
     */
    public function set(
        string $name,mixed $value,
        ?int $expire=null,?string $path=null,?string $domain=null,
        ?bool $secure=null,?bool $httponly=null
    ): void {
        setcookie(
            $this->config->get('cookie.prefix','').$name,
            $value,
            time()+($expire??$this->config->get('cookie.expire',3600)),
            $path??$this->config->get('cookie.path',''),
            $domain??$this->config->get('cookie.domain',''),
            $secure??$this->config->get('cookie.secure',false),
            $httponly??$this->config->get('cookie.httponly',false)
        );
    }

}
