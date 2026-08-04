<?php

namespace AdminService;

use base\Request;
use base\AbstractInputProcessor;
use base\AbstractSession;
use AdminService\Exception;

use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function explode;
use function file_get_contents;
use function function_exists;
use function in_array;
use function is_array;
use function is_subclass_of;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;
use function ucwords;

/**
 * HttpRequest核心类
 */
final class HttpRequest extends Request {

    /**
     * 请求头数据
     * @var Data
     */
    public static Data $request_headers;

    /**
     * GET请求参数
     * @var Data
     */
    public static Data $request_get;
    
    /**
     * POST请求参数
     * @var Data
     */
    public static Data $request_post;
    
    /**
     * Cookie信息
     * @var Data
     */
    public static Data $request_cookie;

    /**
     * Input信息
     * @var Data
     */
    public static Data $request_input;

    /**
     * Server信息
     * @var Data
     */
    public static Data $request_server;

    /**
     * Session信息
     * @var AbstractSession|null
     */
    public static ?AbstractSession $request_session=null;

    /**
     * 表单文件
     * @var UploadFilesForm|null
     */
    public static ?UploadFilesForm $request_files=null;

    /**
     * 原始Input信息
     * @var string
     */
    protected static string $request_raw_input='';

    /**
     * 参数处理顺序
     * @var string
     */
    protected static string $request_order='';

    /**
     * 获取全部请求头信息
     * 
     * @access protected
     * @return array
     */
    protected static function getAllHeaders() {
        // 判断是否存在getallheaders函数
        if(function_exists('getallheaders'))
            return getallheaders();
        // 如果不存在则手动获取
        $headers=[];
        foreach($_SERVER as $key=>$value) {
            if(str_starts_with($key,'HTTP_')) {
                // 去掉前缀并格式化为标准Header格式
                $name=str_replace('_','-',substr($key,5));
                $name=ucwords(strtolower($name),'-');
                $headers[$name]=$value;
            } elseif(in_array($key,['CONTENT_TYPE','CONTENT_LENGTH','CONTENT_MD5'])) {
                // 部分Header不会带HTTP_前缀
                $name=str_replace('_','-',$key);
                $name=ucwords(strtolower($name),'-');
                $headers[$name]=$value;
            }
        }
        return $headers;
    }

    /**
     * 初始化请求
     *
     * @access public
     * @return void
     */
    public static function init(): void {
        self::$request_headers=new Data(self::getAllHeaders());
        self::$request_headers->setCaseSensitive(false)->resetKey();
        self::$request_get=new Data($_GET??[]);
        self::$request_post=new Data($_POST??[]);
        self::$request_cookie=new Data($_COOKIE??[]);
        self::$request_server=new Data($_SERVER??[]);
        self::$request_input=new Data();
        // 处理上传文件信息
        $save_dir=Config::get(
            'request.default.upload.save.dir',
            __DIR__.'/../uploads'
        );
        self::$request_files=new UploadFilesForm($save_dir,$_FILES);
        // 处理input信息
        self::$request_raw_input=@file_get_contents('php://input')?:'';
        // 获取Content-Type的值
        $content_type_header=self::$request_headers->get('content-type','');
        $content_type=strtolower(trim(explode(';',$content_type_header)[0]));
        /** @var array<string, string> $input_list */
        $input_list=Config::get('request.default.input',[]);
        if(array_key_exists($content_type,$input_list)) {
            // 验证是否属于 AbstractInputProcessor
            if(is_subclass_of(
                $input_list[$content_type],AbstractInputProcessor::class
            )) {
                /** @var AbstractInputProcessor $parser*/
                $parser=App::new(
                    $input_list[$content_type],
                    self::$request_raw_input
                );
                self::$request_input->init($parser->toArray());
                // 判断是否需要将input参数与其他参数合并
                $input=Config::get('request.default.param.input',0);
                switch($input) {
                    case self::GET_PARAM:
                        self::$request_get->batchSet(
                            self::$request_input->all()
                        );
                        break;
                    case self::POST_PARAM:
                        self::$request_post->batchSet(
                            self::$request_input->all()
                        );
                        break;
                    case self::COOKIE_PARAM:
                        self::$request_cookie->batchSet(
                            self::$request_input->all()
                        );
                        break;
                }
            }
        }
        // 处理Session信息
        if(Config::get('request.default.session.enable',false)) {
            /** @var AbstractSession $session*/
            $session=App::new(
                Config::get('request.default.session.class',NativeSession::class)
            );
            self::$request_session=$session;
            $session->init();
        }
        // 获取参数处理顺序
        self::$request_order=Config::get('request.default.param.order','CGP');
    }

    /**
     * 获取上传的文件信息,
     * 传入字段名则返回`AbstractUploadFiles`,
     * 不传入则返回`AbstractUploadFilesForm`
     * 
     * @access public
     * @param string|null $name 字段名(null时获取全部)
     * @return UploadFilesForm|UploadFiles
     */
    public static function getUploadFiles(
        ?string $name=null
    ): UploadFilesForm|UploadFiles {
        if($name===null) return self::$request_files;
        $files=self::$request_files->getFilesByField($name);
        if($files===null) return self::$request_files->buildEmpty();
        return $files;
    }


    /**
     * 设置Cookie信息(仅修改`Request`容器内缓存,不同步后续请求,不同步到`Response`)
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    public static function setCookie(string|array $params,string $value=''): void {
        if(is_array($params))
            self::$request_cookie->batchSet($params);
        else self::$request_cookie->set($params,$value);
    }

    /**
     * 获取Cookie参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getCookie(
        string $name,
        mixed $default=null
    ): mixed {
        return self::$request_cookie->get($name,$default);
    }

    /**
     * 获取全部Cookie参数
     * 
     * @access public
     * @return array
     */
    public static function getCookies(): array {
        return self::$request_cookie->all();
    }

    /**
     * 设置Header信息(仅修改`Request`容器内缓存,不同步后续请求,不同步到`Response`)
     * 
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    public static function setHeader(string|array $params,string $value=''): void {
        if(is_array($params))
            self::$request_headers->batchSet($params);
        else self::$request_headers->set($params,$value);
    }

    /**
     * 获取Header参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getHeader(
        string $name,
        mixed $default=null
    ): mixed {
        return self::$request_headers->get($name,$default);
    }

    /**
     * 获取全部Header参数
     * 
     * @access public
     * @return array
     */
    public static function getHeaders(): array {
        return self::$request_headers->all();
    }

    /**
     * 设置Input参数
     *
     * @access public
     * @param string|array $params 参数
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    public static function setInput(
        string|array $params,
        string $value=''
    ): void {
        if(is_array($params))
            self::$request_input->batchSet($params);
        else self::$request_input->set($params,$value);
    }

    /**
     * 获取Input参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getInput(
        string $name,
        mixed $default=null
    ): mixed {
        return self::$request_input->get($name,$default);
    }

    /**
     * 获取全部Input参数
     * 
     * @access public
     * @return array
     */
    public static function getInputs(): array {
        return self::$request_input->all();
    }

    /**
     * 获取原始Input数据
     * 
     * @access public
     * @return string
     */
    public static function getRawInput(): string {
        return self::$request_raw_input;
    }

    /**
     * 设置Server参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    public static function setServer(
        string|array $params,
        mixed $value=null
    ): void {
        if(is_array($params))
            self::$request_server->batchSet($params);
        else self::$request_server->set($params,$value);
    }

    /**
     * 获取Server参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getServer(
        string $name,
        mixed $default=null
    ): mixed {
        return self::$request_server->get($name,$default);
    }

    /**
     * 获取全部Server参数
     * 
     * @access public
     * @return array
     */
    public static function getServers(): array {
        return self::$request_server->all();
    }

    /**
     * 设置Get参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    public static function setGet(
        string|array $params,
        mixed $value=null
    ): void {
        if(is_array($params))
            self::$request_get->batchSet($params);
        else self::$request_get->set($params,$value);
    }

    /**
     * 获取Get参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getGet(
        string $name,
        mixed $default=null
    ): mixed {
        return self::$request_get->get($name,$default);
    }

    /**
     * 获取全部GET参数
     * 
     * @access public
     * @return array
     */
    public static function getGets(): array {
        return self::$request_get->all();
    }

    /**
     * 设置Post参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    public static function setPost(
        string|array $params,
        mixed $value=null
    ): void {
        if(is_array($params))
            self::$request_post->batchSet($params);
        else self::$request_post->set($params,$value);
    }

    /**
     * 获取Post参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getPost(
        string $name,
        mixed $default=null
    ): mixed {
        return self::$request_post->get($name,$default);
    }

    /**
     * 获取全部POST参数
     * 
     * @access public
     * @return array
     */
    public static function getPosts(): array {
        return self::$request_post->all();
    }

    /**
     * 设置Session参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Cookie值($params 参数为数组时此参数无效) 
     * @return void
     */
    public static function setSession(
        string|array $params,
        string $value=''
    ): void {
        if(self::$request_session==null)
            throw new Exception('Session is not initialized.');
        if(is_array($params))
            foreach($params as $key=>$val)
                self::$request_session->set($key,$val);
        else self::$request_session->set($params,$value);
    }

    /**
     * 获取Session参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getSession(
        string $name,
        mixed $default=null
    ): mixed {
        if(self::$request_session==null)
            throw new Exception('Session is not initialized.');
        return self::$request_session->get($name,$default);
    }

    /**
     * 获取请求参数键名
     * 
     * @access public
     * @param int $type 参数类型
     * @return array
     */
    public static function getParamKeys(
        int $type=self::ALL_PARAM
    ): array {
        $keys=[];
        $order=self::$request_order;
        // 按顺序追加键名
        foreach(str_split(strtoupper($order)) as $ch) {
            switch($ch) {
                case 'G':
                    if($type===self::ALL_PARAM||$type===self::GET_PARAM)
                        $keys=array_merge($keys,self::$request_get->keys());
                    break;
                case 'P':
                    if($type===self::ALL_PARAM||$type===self::POST_PARAM)
                        $keys=array_merge($keys,self::$request_post->keys());
                    break;
                case 'C':
                    if($type===self::ALL_PARAM||$type===self::COOKIE_PARAM)
                        $keys=array_merge($keys,self::$request_cookie->keys());
                    break;
            }
        }
        // 去重
        return array_values(array_unique($keys));
    }

    /**
     * 通过键名获取请求参数
     * 
     * @access public
     * @param string $name 参数名
     * @param int $type 参数类型
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function getParam(
        string $name,
        int $type=self::ALL_PARAM,
        mixed $default=null
    ): mixed {
        // 获取顺序
        $order=self::$request_order;
        // 如果是 ALL_PARAM，就按顺序找
        if($type===self::ALL_PARAM) {
            foreach(str_split(strtoupper($order)) as $ch) {
                switch($ch) {
                    case 'G':
                        if(self::$request_get->has($name))
                            return self::$request_get->get($name);
                        break;
                    case 'P':
                        if(self::$request_post->has($name))
                            return self::$request_post->get($name);
                        break;
                    case 'C':
                        if(self::$request_cookie->has($name))
                            return self::$request_cookie->get($name);
                        break;
                }
            }
            return $default;
        }
        // 仅查询单一来源
        return match($type) {
            self::GET_PARAM=>self::$request_get->get($name,$default),
            self::POST_PARAM=>self::$request_post->get($name,$default),
            self::COOKIE_PARAM=>self::$request_cookie->get($name,$default),
            default=>$default,
        };
    }

    /**
     * 通过键名设置请求参数
     * 
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value 值($params 参数为数组时此参数无效)
     * @param int $type 参数类型
     * @return void
     */
    public static function setParam(
        string|array $params,
        mixed $value=null,
        int $type=self::ALL_PARAM
    ): void {
        $assign=is_array($params)?$params:[$params=>$value];
        $apply=function(Data $target) use($assign) {
            foreach($assign as $k=>$v) {
                $target->set($k,$v);
            }
        };
        if($type===self::ALL_PARAM||$type===self::GET_PARAM)
            $apply(self::$request_get);
        if($type===self::ALL_PARAM||$type===self::POST_PARAM)
            $apply(self::$request_post);
        if($type===self::ALL_PARAM||$type===self::COOKIE_PARAM)
            $apply(self::$request_cookie);
    }

    /**
     * 通过键名删除请求参数
     * 
     * @access public
     * @param string|array $params 参数名或参数组
     * @param int $type 参数类型
     * @return void
     */
    public static function removeParam(
        string|array $params,
        int $type=self::ALL_PARAM
    ): void {
        $list=is_array($params)?$params:[$params];
        $remove=function(Data $target) use($list) {
            foreach($list as $k) {
                $target->delete($k);
            }
        };
        if($type===self::ALL_PARAM||$type===self::GET_PARAM)
            $remove(self::$request_get);
        if($type===self::ALL_PARAM||$type===self::POST_PARAM)
            $remove(self::$request_post);
        if($type===self::ALL_PARAM||$type===self::COOKIE_PARAM)
            $remove(self::$request_cookie);
    }

    /**
     * 获取上传文件实例
     * 
     * @access public
     * @return UploadFilesForm
     */
    public static function getUploadFilesInstance(): UploadFilesForm {
        return self::$request_files;
    }

    /**
     * 获取Session实例
     * 
     * @access public
     * @return AbstractSession
     */
    public static function getSessionInstance(): AbstractSession {
        return self::$request_session;
    }

}