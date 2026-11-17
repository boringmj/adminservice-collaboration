<?php

namespace AdminService;

use base\ConfigInterface;
use base\Cookie as BaseCookie;
use base\CookieOptions;

use function in_array;
use function is_array;
use function preg_match;
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
     * 错误码
     */
    private const ERROR_INVALID_SAMESITE=100402;
    private const ERROR_SAMESITE_REQUIRES_SECURE=100403;
    private const ERROR_INVALID_COOKIE=100405;

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
     * 批量设置 Cookie
     *
     * @access public
     * @param array<string,CookieOptions|string|array<string,mixed>> $cookies 名称 => 属性
     * @return void
     */
    public function setAll(array $cookies): void {
        foreach($cookies as $name=>$value)
            $this->set((string)$name,$this->toOptions($value));
    }

    /**
     * 设置单个 Cookie
     *
     * @access public
     * @param string $name 名称
     * @param CookieOptions|string $value 属性或值
     * @return void
     * @throws Exception 名称或属性非法
     */
    public function set(string $name,CookieOptions|string $value): void {
        $full_name=$this->config->get('cookie.prefix','').$name;
        $this->assertName($full_name);
        $cookie=$this->normalize($value);
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
            $full_name,
            $cookie['value'],
            $header
        );
    }

    /**
     * 删除 Cookie
     *
     * @access public
     * @param string $name 名称
     * @param CookieOptions|null $options 保留的路径 / 域名等属性
     * @return void
     */
    public function delete(string $name,?CookieOptions $options=null): void {
        $options??=new CookieOptions();
        $this->set($name,new CookieOptions(
            '',
            1,
            $options->path,
            $options->domain,
            $options->secure,
            $options->httponly,
            $options->samesite
        ));
    }

    /**
     * 构建单个待下发的 Cookie 描述
     *
     * @access public
     * @param string $name 名称
     * @param CookieOptions|string $value 属性或值
     * @return array{
     *     value:string,
     *     expire:int,
     *     path:string,
     *     domain:string,
     *     secure:bool,
     *     httponly:bool,
     *     samesite:string
     * }
     */
    public function build(string $name,CookieOptions|string $value): array {
        $this->assertName($this->config->get('cookie.prefix','').$name);
        return $this->normalize($value);
    }

    /**
     * 批量构建待下发的 Cookie 描述
     *
     * @access public
     * @param array<string,CookieOptions|string|array<string,mixed>> $cookies 名称 => 属性
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
    public function buildAll(array $cookies): array {
        $result=array();
        foreach($cookies as $name=>$value)
            $result[$this->config->get('cookie.prefix','').$name]=$this->build((string)$name,$this->toOptions($value));
        return $result;
    }

    /**
     * 转为 Cookie 属性对象
     *
     * @access private
     * @param CookieOptions|string|array<string,mixed> $value 属性或值
     * @return CookieOptions
     */
    private function toOptions(CookieOptions|string|array $value): CookieOptions {
        if($value instanceof CookieOptions)
            return $value;
        if(is_array($value)) {
            try {
                return CookieOptions::fromArray($value);
            } catch(\InvalidArgumentException $e) {
                throw new Exception($e->getMessage(),self::ERROR_INVALID_COOKIE,array(
                    'options'=>$value
                ));
            }
        }
        return new CookieOptions($value);
    }

    /**
     * 归一化 Cookie 属性
     *
     * - 未给属性时套用配置默认值; SameSite 只接受 Lax / Strict / None
     *
     * @access private
     * @param CookieOptions|string $value 属性或值
     * @return array{
     *     value:string,
     *     expire:int,
     *     path:string,
     *     domain:string,
     *     secure:bool,
     *     httponly:bool,
     *     samesite:string
     * }
     * @throws Exception 属性非法
     */
    private function normalize(CookieOptions|string $value): array {
        $options=$value instanceof CookieOptions?$value:new CookieOptions($value);
        $samesite=(string)($options->samesite??$this->config->get('cookie.samesite','Lax'));
        $secure=(bool)($options->secure??$this->config->get('cookie.secure',false));
        if($samesite!=='') {
            $samesite=ucfirst(strtolower($samesite));
            if(!in_array($samesite,array('Lax','Strict','None'),true))
                throw new Exception('Cookie SameSite value is invalid: '.$samesite,self::ERROR_INVALID_SAMESITE,array(
                    'samesite'=>$samesite
                ));
            if($samesite==='None'&&!$secure)
                throw new Exception('Cookie SameSite=None requires secure=true',self::ERROR_SAMESITE_REQUIRES_SECURE,array(
                    'samesite'=>$samesite
                ));
        }
        $path=(string)($options->path??$this->config->get('cookie.path',''));
        $domain=(string)($options->domain??$this->config->get('cookie.domain',''));
        foreach(array('value'=>$options->value,'path'=>$path,'domain'=>$domain) as $field=>$item) {
            if(preg_match('/[\x00-\x1F\x7F]/',$item)===1)
                throw new Exception('Cookie '.$field.' contains control characters.',self::ERROR_INVALID_COOKIE,array(
                    'field'=>$field
                ));
            // setcookie() 拒绝 path / domain 中的空格、逗号与分号
            if($field!=='value'&&preg_match('/[\x20,;]/',$item)===1)
                throw new Exception('Cookie '.$field.' contains invalid characters.',self::ERROR_INVALID_COOKIE,array(
                    'field'=>$field
                ));
        }
        return array(
            'value'=>$options->value,
            'expire'=>$options->expires??time()+(int)$this->config->get('cookie.expire',3600),
            'path'=>$path,
            'domain'=>$domain,
            'secure'=>$secure,
            'httponly'=>(bool)($options->httponly??$this->config->get('cookie.httponly',true)),
            'samesite'=>$samesite
        );
    }

    /**
     * 校验 Cookie 名称
     *
     * @access private
     * @param string $name 名称
     * @return void
     * @throws Exception 名称非法
     */
    private function assertName(string $name): void {
        if(preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/',$name)!==1)
            throw new Exception('Cookie name is invalid.',self::ERROR_INVALID_COOKIE,array(
                'name'=>$name
            ));
    }

}
