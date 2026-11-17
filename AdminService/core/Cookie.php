<?php

namespace AdminService;

use base\ConfigInterface;
use base\Cookie as BaseCookie;

use function in_array;
use function is_array;
use function setcookie;
use function strtolower;
use function time;
use function ucfirst;

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
                    $value['httponly']??null,
                    $value['samesite']??null
                );
            else
                $this->set($key,$value);
    }

    /**
     * 按配置构建待下发的 Cookie 描述(不发送)
     *
     * - 键名带 `cookie.prefix`; 未指定属性时套用配置默认值
     *
     * @access public
     * @param array<string,mixed> $data 数据
     * @return array<string,array{
     *     value:string,
     *     expire:int,
     *     path:string,
     *     domain:string,
     *     secure:bool,
     *     httponly:bool,
     *     samesite:string
     * }>
     */
    public function buildByArray(array $data): array {
        $cookies=array();
        foreach($data as $key=>$value) {
            $options=is_array($value)?$value:array('value'=>$value);
            $cookies[$this->config->get('cookie.prefix','').$key]=$this->normalize($options);
        }
        return $cookies;
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
     * @param string|null $samesite 跨站发送策略
     * @return void
     * @throws Exception SameSite 取值非法
     */
    public function set(
        string $name,mixed $value,
        ?int $expire=null,?string $path=null,?string $domain=null,
        ?bool $secure=null,?bool $httponly=null,?string $samesite=null
    ): void {
        $options=array('value'=>$value);
        if($expire!==null)
            $options['expire']=$expire;
        if($path!==null)
            $options['path']=$path;
        if($domain!==null)
            $options['domain']=$domain;
        if($secure!==null)
            $options['secure']=$secure;
        if($httponly!==null)
            $options['httponly']=$httponly;
        if($samesite!==null)
            $options['samesite']=$samesite;
        $cookie=$this->normalize($options);
        $header=array(
            'expires'=>$cookie['expire'],
            'path'=>$cookie['path'],
            'domain'=>$cookie['domain'],
            'secure'=>$cookie['secure'],
            'httponly'=>$cookie['httponly']
        );
        if($cookie['samesite']!=='')
            $header['samesite']=$cookie['samesite'];
        setcookie(
            $this->config->get('cookie.prefix','').$name,
            $cookie['value'],
            $header
        );
    }

    /**
     * 归一化单个 Cookie 描述
     *
     * - 相对过期时间转为绝对时间; 未给属性时套用配置默认值
     * - SameSite 只接受 Lax / Strict / None, 空串表示不设置
     *
     * @access private
     * @param array<string,mixed> $options Cookie 选项
     * @return array{
     *     value:string,
     *     expire:int,
     *     path:string,
     *     domain:string,
     *     secure:bool,
     *     httponly:bool,
     *     samesite:string
     * }
     * @throws Exception SameSite 取值非法
     */
    private function normalize(array $options): array {
        $samesite=(string)($options['samesite']??$this->config->get('cookie.samesite','Lax'));
        $secure=(bool)($options['secure']??$this->config->get('cookie.secure',false));
        if($samesite!=='') {
            $samesite=ucfirst(strtolower($samesite));
            if(!in_array($samesite,array('Lax','Strict','None'),true))
                throw new Exception('Cookie SameSite value is invalid: '.$samesite,100402,array(
                    'samesite'=>$samesite
                ));
            if($samesite==='None'&&!$secure)
                throw new Exception('Cookie SameSite=None requires secure=true',100403,array(
                    'samesite'=>$samesite
                ));
        }
        return array(
            'value'=>(string)($options['value']??''),
            'expire'=>time()+(int)($options['expire']??$this->config->get('cookie.expire',3600)),
            'path'=>(string)($options['path']??$this->config->get('cookie.path','')),
            'domain'=>(string)($options['domain']??$this->config->get('cookie.domain','')),
            'secure'=>$secure,
            'httponly'=>(bool)($options['httponly']??$this->config->get('cookie.httponly',true)),
            'samesite'=>$samesite
        );
    }

}
