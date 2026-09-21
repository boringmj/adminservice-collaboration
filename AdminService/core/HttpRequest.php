<?php

namespace AdminService;

use base\Request;
use base\AbstractInputProcessor;

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
 *
 * - 输入在构造时一次就位, 不持有静态状态, 每个请求一个实例
 * - 上传表单惰性构建: 未访问上传接口时既不读配置也不触碰上传目录
 */
final class HttpRequest extends Request {

    /**
     * 请求头数据
     * @var Data
     */
    protected Data $headers;

    /**
     * GET请求参数(查询字符串)
     * @var Data
     */
    protected Data $query;

    /**
     * POST请求参数
     * @var Data
     */
    protected Data $post;

    /**
     * Cookie信息
     * @var Data
     */
    protected Data $cookie;

    /**
     * Input信息
     * @var Data
     */
    protected Data $input;

    /**
     * Server信息
     * @var Data
     */
    protected Data $server;

    /**
     * 原始Input信息
     * @var string
     */
    protected string $raw_input='';

    /**
     * 参数处理顺序
     * @var string
     */
    protected string $order='';

    /**
     * 原始上传文件数组
     * @var array<string,mixed>
     */
    protected array $raw_files=array();

    /**
     * 表单文件(首次访问上传接口时构建)
     * @var UploadFilesForm|null
     */
    protected ?UploadFilesForm $file_form=null;

    /**
     * 构造方法
     *
     * - `$sources` 为可选输入源, 缺省读超全局; 测试可注入以避免触碰 `$_GET` 等
     * - 支持的键: `headers` / `query` / `post` / `cookie` / `server` / `rawInput` / `files`
     *
     * @access public
     * @param array<string,mixed> $sources 输入源
     */
    public function __construct(array $sources=array()) {
        $this->headers=(new Data(self::parseHeaders($sources)))
            ->setCaseSensitive(false)->resetKey();
        $this->query=new Data($sources['query']??$_GET??array());
        $this->post=new Data($sources['post']??$_POST??array());
        $this->cookie=new Data($sources['cookie']??$_COOKIE??array());
        $this->server=new Data($sources['server']??$_SERVER??array());
        $this->raw_files=$sources['files']??$_FILES??array();
        $this->raw_input=$sources['rawInput']??(@file_get_contents('php://input')?:'');
        // 解析Input信息
        $this->input=new Data();
        $this->parseInput();
        // 获取参数处理顺序
        $this->order=Config::get('request.default.param.order','CGP');
    }

    /**
     * 解析请求头
     *
     * - 显式传入 `headers` 时直接采用; 显式传入 `server` 时由其推导(便于测试注入)
     *
     * @access private
     * @param array<string,mixed> $sources 输入源
     * @return array<string,mixed>
     */
    private static function parseHeaders(array $sources): array {
        if(isset($sources['headers']))
            return $sources['headers'];
        // 未显式传入server时优先使用getallheaders
        if(!isset($sources['server'])&&function_exists('getallheaders'))
            return getallheaders();
        $server=$sources['server']??$_SERVER??array();
        $headers=array();
        foreach($server as $key=>$value) {
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
     * 解析Input信息
     *
     * - 按Content-Type查找处理器, 解析结果可选合并进get/post/cookie(见配置项`request.default.param.input`)
     *
     * @access private
     * @return void
     */
    private function parseInput(): void {
        // 获取Content-Type的值
        $content_type_header=$this->headers->get('content-type','');
        $content_type=strtolower(trim(explode(';',$content_type_header)[0]));
        /** @var array<string, string> $input_list */
        $input_list=Config::get('request.default.input',[]);
        if(!array_key_exists($content_type,$input_list))
            return;
        // 验证是否属于 AbstractInputProcessor
        if(!is_subclass_of($input_list[$content_type],AbstractInputProcessor::class))
            return;
        /** @var AbstractInputProcessor $parser */
        $parser=App::new($input_list[$content_type])->parse($this->raw_input);
        $this->input->init($parser->toArray());
        // 判断是否需要将input参数与其他参数合并
        $input=Config::get('request.default.param.input',0);
        switch($input) {
            case self::GET_PARAM:
                $this->query->batchSet($this->input->all());
                break;
            case self::POST_PARAM:
                $this->post->batchSet($this->input->all());
                break;
            case self::COOKIE_PARAM:
                $this->cookie->batchSet($this->input->all());
                break;
        }
    }

    /**
     * 获取表单文件(首次访问时构建)
     *
     * @access private
     * @return UploadFilesForm
     */
    private function fileForm(): UploadFilesForm {
        if($this->file_form===null) {
            $save_dir=(string)Config::get(
                'request.default.upload.save.dir',
                __DIR__.'/../uploads'
            );
            $this->file_form=new UploadFilesForm($save_dir,$this->raw_files);
        }
        return $this->file_form;
    }

    /**
     * 获取上传的文件信息,
     * 传入字段名则返回`UploadFiles`,
     * 不传入则返回`UploadFilesForm`
     *
     * @access public
     * @param string|null $name 字段名(null时获取全部)
     * @return UploadFilesForm|UploadFiles
     */
    public function getUploadFiles(
        ?string $name=null
    ): UploadFilesForm|UploadFiles {
        if($name===null) return $this->fileForm();
        $files=$this->fileForm()->getFilesByField($name);
        if($files===null) return $this->fileForm()->buildEmpty();
        return $files;
    }

    /**
     * 设置Cookie信息(仅修改`Request`容器内缓存,不同步后续请求,不同步到`Response`)
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setCookie(string|array $params,string $value=''): void {
        if(is_array($params))
            $this->cookie->batchSet($params);
        else $this->cookie->set($params,$value);
    }

    /**
     * 获取Cookie参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getCookie(
        string $name,
        mixed $default=null
    ): mixed {
        return $this->cookie->get($name,$default);
    }

    /**
     * 获取全部Cookie参数
     *
     * @access public
     * @return array
     */
    public function getCookies(): array {
        return $this->cookie->all();
    }

    /**
     * 设置Header信息(仅修改`Request`容器内缓存,不同步后续请求,不同步到`Response`)
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Header值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setHeader(string|array $params,string $value=''): void {
        if(is_array($params))
            $this->headers->batchSet($params);
        else $this->headers->set($params,$value);
    }

    /**
     * 获取Header参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getHeader(
        string $name,
        mixed $default=null
    ): mixed {
        return $this->headers->get($name,$default);
    }

    /**
     * 获取全部Header参数
     *
     * @access public
     * @return array
     */
    public function getHeaders(): array {
        return $this->headers->all();
    }

    /**
     * 设置Input参数
     *
     * @access public
     * @param string|array $params 参数
     * @param string $value Input值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setInput(
        string|array $params,
        string $value=''
    ): void {
        if(is_array($params))
            $this->input->batchSet($params);
        else $this->input->set($params,$value);
    }

    /**
     * 获取Input参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getInput(
        string $name,
        mixed $default=null
    ): mixed {
        return $this->input->get($name,$default);
    }

    /**
     * 获取全部Input参数
     *
     * @access public
     * @return array
     */
    public function getInputs(): array {
        return $this->input->all();
    }

    /**
     * 获取原始Input数据
     *
     * @access public
     * @return string
     */
    public function getRawInput(): string {
        return $this->raw_input;
    }

    /**
     * 设置Server参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Server值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setServer(
        string|array $params,
        mixed $value=null
    ): void {
        if(is_array($params))
            $this->server->batchSet($params);
        else $this->server->set($params,$value);
    }

    /**
     * 获取Server参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getServer(
        string $name,
        mixed $default=null
    ): mixed {
        return $this->server->get($name,$default);
    }

    /**
     * 获取全部Server参数
     *
     * @access public
     * @return array
     */
    public function getServers(): array {
        return $this->server->all();
    }

    /**
     * 设置Get参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Get值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setGet(
        string|array $params,
        mixed $value=null
    ): void {
        if(is_array($params))
            $this->query->batchSet($params);
        else $this->query->set($params,$value);
    }

    /**
     * 获取Get参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getGet(
        string $name,
        mixed $default=null
    ): mixed {
        return $this->query->get($name,$default);
    }

    /**
     * 获取全部GET参数
     *
     * @access public
     * @return array
     */
    public function getGets(): array {
        return $this->query->all();
    }

    /**
     * 设置Post参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Post值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setPost(
        string|array $params,
        mixed $value=null
    ): void {
        if(is_array($params))
            $this->post->batchSet($params);
        else $this->post->set($params,$value);
    }

    /**
     * 获取Post参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getPost(
        string $name,
        mixed $default=null
    ): mixed {
        return $this->post->get($name,$default);
    }

    /**
     * 获取全部POST参数
     *
     * @access public
     * @return array
     */
    public function getPosts(): array {
        return $this->post->all();
    }

    /**
     * 获取请求参数键名
     *
     * @access public
     * @param int $type 参数类型
     * @return array
     */
    public function getParamKeys(
        int $type=self::ALL_PARAM
    ): array {
        $keys=[];
        // 按顺序追加键名
        foreach(str_split(strtoupper($this->order)) as $ch) {
            switch($ch) {
                case 'G':
                    if($type===self::ALL_PARAM||$type===self::GET_PARAM)
                        $keys=array_merge($keys,$this->query->keys());
                    break;
                case 'P':
                    if($type===self::ALL_PARAM||$type===self::POST_PARAM)
                        $keys=array_merge($keys,$this->post->keys());
                    break;
                case 'C':
                    if($type===self::ALL_PARAM||$type===self::COOKIE_PARAM)
                        $keys=array_merge($keys,$this->cookie->keys());
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
    public function getParam(
        string $name,
        int $type=self::ALL_PARAM,
        mixed $default=null
    ): mixed {
        // 如果是 ALL_PARAM，就按顺序找
        if($type===self::ALL_PARAM) {
            foreach(str_split(strtoupper($this->order)) as $ch) {
                switch($ch) {
                    case 'G':
                        if($this->query->has($name))
                            return $this->query->get($name);
                        break;
                    case 'P':
                        if($this->post->has($name))
                            return $this->post->get($name);
                        break;
                    case 'C':
                        if($this->cookie->has($name))
                            return $this->cookie->get($name);
                        break;
                }
            }
            return $default;
        }
        // 仅查询单一来源
        return match($type) {
            self::GET_PARAM=>$this->query->get($name,$default),
            self::POST_PARAM=>$this->post->get($name,$default),
            self::COOKIE_PARAM=>$this->cookie->get($name,$default),
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
    public function setParam(
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
            $apply($this->query);
        if($type===self::ALL_PARAM||$type===self::POST_PARAM)
            $apply($this->post);
        if($type===self::ALL_PARAM||$type===self::COOKIE_PARAM)
            $apply($this->cookie);
    }

    /**
     * 通过键名删除请求参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param int $type 参数类型
     * @return void
     */
    public function removeParam(
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
            $remove($this->query);
        if($type===self::ALL_PARAM||$type===self::POST_PARAM)
            $remove($this->post);
        if($type===self::ALL_PARAM||$type===self::COOKIE_PARAM)
            $remove($this->cookie);
    }

    /**
     * 获取上传文件实例
     *
     * @access public
     * @return UploadFilesForm
     */
    public function getUploadFilesInstance(): UploadFilesForm {
        return $this->fileForm();
    }

}
