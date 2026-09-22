<?php

namespace AdminService;

use base\Request;
use base\AbstractInputProcessor;
use base\Container as ContainerContract;

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
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function strtoupper;
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
     * 容器契约(由容器构建本对象时注入; 直接 new 时为 null)
     * @var ContainerContract|null
     */
    protected ?ContainerContract $container=null;

    /**
     * 请求头数据
     * @var Data
     */
    protected Data $headers;

    /**
     * 查询串参数
     * @var Data
     */
    protected Data $query;

    /**
     * POST参数
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
     * 请求属性(程序内部附加, 如路由参数)
     * @var Data
     */
    protected Data $attributes;

    /**
     * 原始请求体
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
     * - `$container` 由容器构建本对象时注入(输入处理器因此可依赖注入);直接 `new` 时为 null
     *
     * @access public
     * @param array<string,mixed> $sources 输入源
     * @param ContainerContract|null $container 容器契约
     */
    public function __construct(array $sources=array(),?ContainerContract $container=null) {
        // 容器由容器构建时注入(输入处理器因此可依赖注入);直接 new 时为 null
        $this->container=$container;
        $this->headers=(new Data(self::parseHeaders($sources)))
            ->setCaseSensitive(false)->resetKey();
        $this->query=new Data($sources['query']??$_GET??array());
        $this->post=new Data($sources['post']??$_POST??array());
        $this->cookie=new Data($sources['cookie']??$_COOKIE??array());
        $this->server=new Data($sources['server']??$_SERVER??array());
        $this->attributes=new Data();
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
     * - 按Content-Type查找处理器, 解析结果可选合并进query/post/cookie(见配置项`request.default.param.input`)
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
        // 处理器经容器构建(可依赖注入);直接 new 出来的请求对象退化为直接实例化
        $parser_class=$input_list[$content_type];
        $parser=($this->container===null?new $parser_class():$this->container->build($parser_class))->parse($this->raw_input);
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
     * 获取查询串参数(不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    public function query(?string $name=null,mixed $default=null): mixed {
        if($name===null) return $this->query->all();
        return $this->query->get($name,$default);
    }

    /**
     * 获取POST参数(不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    public function post(?string $name=null,mixed $default=null): mixed {
        if($name===null) return $this->post->all();
        return $this->post->get($name,$default);
    }

    /**
     * 获取Cookie参数(不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    public function cookie(?string $name=null,mixed $default=null): mixed {
        if($name===null) return $this->cookie->all();
        return $this->cookie->get($name,$default);
    }

    /**
     * 获取Header参数(不传参数名时返回全部, 键名大小写不敏感)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    public function header(?string $name=null,mixed $default=null): mixed {
        if($name===null) return $this->headers->all();
        return $this->headers->get($name,$default);
    }

    /**
     * 获取Input参数(不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    public function input(?string $name=null,mixed $default=null): mixed {
        if($name===null) return $this->input->all();
        return $this->input->get($name,$default);
    }

    /**
     * 获取Server参数(不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    public function server(?string $name=null,mixed $default=null): mixed {
        if($name===null) return $this->server->all();
        return $this->server->get($name,$default);
    }

    /**
     * 获取原始请求体
     *
     * @access public
     * @return string
     */
    public function rawInput(): string {
        return $this->raw_input;
    }

    /**
     * 获取请求方法(大写, 缺省为GET)
     *
     * @access public
     * @return string
     */
    public function method(): string {
        return strtoupper((string)$this->server->get('REQUEST_METHOD','GET'));
    }

    /**
     * 获取请求URI(含查询串)
     *
     * @access public
     * @return string
     */
    public function uri(): string {
        return (string)$this->server->get('REQUEST_URI','');
    }

    /**
     * 获取请求路径(不含查询串, 去掉末尾斜杠)
     *
     * @access public
     * @return string
     */
    public function path(): string {
        return '/'.trim(explode('?',$this->uri(),2)[0],'/');
    }

    /**
     * 判断客户端是否接受某内容类型
     *
     * @access public
     * @param string $type 内容类型
     * @return bool
     */
    public function accepts(string $type): bool {
        $accept=Negotiator::parse($this->acceptHeader());
        // 无 Accept 头视为接受任意类型
        if($accept===array()) return true;
        return Negotiator::accepts($accept,$type);
    }

    /**
     * 判断客户端是否明确要求JSON
     *
     * @access public
     * @return bool
     */
    public function wantsJson(): bool {
        foreach(Negotiator::parse($this->acceptHeader()) as $type=>$q) {
            if($q<=0) continue;
            if($type==='application/json'||str_ends_with($type,'/json')||str_contains($type,'+json'))
                return true;
        }
        return false;
    }

    /**
     * 判断本次请求是否期望JSON响应
     *
     * @access public
     * @return bool
     */
    public function expectsJson(): bool {
        if(strtolower((string)$this->header('x-requested-with',''))==='xmlhttprequest')
            return true;
        return $this->wantsJson();
    }

    /**
     * 取原始Accept头
     *
     * @access private
     * @return string
     */
    private function acceptHeader(): string {
        return (string)$this->header('accept','');
    }

    /**
     * 设置查询串参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value 值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setQuery(string|array $params,mixed $value=null): void {
        if(is_array($params))
            $this->query->batchSet($params);
        else $this->query->set($params,$value);
    }

    /**
     * 设置POST参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value 值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setPost(string|array $params,mixed $value=null): void {
        if(is_array($params))
            $this->post->batchSet($params);
        else $this->post->set($params,$value);
    }

    /**
     * 设置Cookie信息(仅修改本请求实例的Cookie容器, 不同步到`Response`)
     *
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
     * 设置Header信息(仅修改本请求实例的Header容器, 不同步到`Response`)
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
     * 设置Input参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Input值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setInput(string|array $params,string $value=''): void {
        if(is_array($params))
            $this->input->batchSet($params);
        else $this->input->set($params,$value);
    }

    /**
     * 设置Server参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value 值($params 参数为数组时此参数无效)
     * @return void
     */
    public function setServer(string|array $params,mixed $value=null): void {
        if(is_array($params))
            $this->server->batchSet($params);
        else $this->server->set($params,$value);
    }

    /**
     * 通过键名获取请求参数
     *
     * - 属性(如路由参数)优先于任一输入区: 路径结构优先于查询串
     *
     * @access public
     * @param string $name 参数名
     * @param int $type 参数类型
     * @param mixed $default 默认值
     * @return mixed
     */
    public function param(
        string $name,
        int $type=self::ALL_PARAM,
        mixed $default=null
    ): mixed {
        // 如果是 ALL_PARAM，就按顺序找
        if($type===self::ALL_PARAM) {
            if($this->attributes->has($name))
                return $this->attributes->get($name);
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
     * 获取请求参数键名
     *
     * @access public
     * @param int $type 参数类型
     * @return array
     */
    public function paramKeys(int $type=self::ALL_PARAM): array {
        // 属性(如路由参数)优先
        $keys=$type===self::ALL_PARAM?$this->attributes->keys():array();
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
     * 设置请求属性(程序内部附加的数据, 如路由参数)
     *
     * @access public
     * @param string $name 属性名
     * @param mixed $value 属性值
     * @return void
     */
    public function setAttribute(string $name,mixed $value): void {
        $this->attributes->set($name,$value);
    }

    /**
     * 获取请求属性
     *
     * @access public
     * @param string $name 属性名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function attribute(string $name,mixed $default=null): mixed {
        return $this->attributes->get($name,$default);
    }

    /**
     * 获取全部请求属性
     *
     * @access public
     * @return array
     */
    public function attributes(): array {
        return $this->attributes->all();
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
    public function file(?string $name=null): UploadFilesForm|UploadFiles {
        if($name===null) return $this->fileForm();
        $files=$this->fileForm()->getFilesByField($name);
        if($files===null) return $this->fileForm()->buildEmpty();
        return $files;
    }

    /**
     * 获取表单文件实例(全部上传文件)
     *
     * @access public
     * @return UploadFilesForm
     */
    public function files(): UploadFilesForm {
        return $this->fileForm();
    }

}
