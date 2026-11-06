<?php

namespace AdminService\Console\Command;

use AdminService\Config\Repository;
use AdminService\Console\Input;
use AdminService\Console\Output;
use base\AbstractCommand;

use function escapeshellarg;
use function is_dir;
use function is_file;
use function is_string;
use function passthru;
use function preg_match;
use function rtrim;

/**
 * 启动 PHP 内置 Web 服务
 *
 * @access public
 * @package AdminService\Console\Command
 * @version 1.0.0
 */
final class ServerStartCommand extends AbstractCommand {

    /**
     * 配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 构造方法
     *
     * @access public
     * @param Repository $config 配置仓储
     */
    public function __construct(Repository $config) {
        $this->config=$config;
    }

    /**
     * 规范命令路径
     *
     * @access public
     * @return string
     */
    public function name(): string {
        return 'server start';
    }

    /**
     * 命令说明
     *
     * @access public
     * @return string
     */
    public function description(): string {
        return '启动 PHP 内置 Web 服务';
    }

    /**
     * 命令别名
     *
     * @access public
     * @return array<int,string>
     */
    public function aliases(): array {
        return array('serve');
    }

    /**
     * 需要接收值的选项
     *
     * @access public
     * @return array<int,string>
     */
    public function valueOptions(): array {
        return array('host','H','port','p','docroot','t','php','router');
    }

    /**
     * 布尔选项
     *
     * @access public
     * @return array<int,string>
     */
    public function booleanOptions(): array {
        return array('dry-run');
    }

    /**
     * 选项说明
     *
     * @access public
     * @return array<int,array{names:array<int,string>,value:string|null,description:string}>
     */
    public function optionHelp(): array {
        return array(
            array('names'=>array('host','H'),'value'=>'地址','description'=>'监听地址, 默认 CLI_SERVE_HOST 或 localhost'),
            array('names'=>array('port','p'),'value'=>'端口','description'=>'监听端口, 默认 CLI_SERVE_PORT 或 8000'),
            array('names'=>array('docroot','t'),'value'=>'目录','description'=>'文档根目录, 默认 public/'),
            array('names'=>array('php'),'value'=>'可执行文件','description'=>'PHP 可执行文件, 默认当前 PHP'),
            array('names'=>array('router'),'value'=>'文件','description'=>'路由脚本, 默认不指定'),
            array('names'=>array('dry-run'),'value'=>null,'description'=>'只输出将执行的命令, 不启动服务'),
        );
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'server start [--host=HOST] [--port=PORT] [--docroot=PATH] [--php=PHP] [--router=FILE] [--dry-run]';
    }

    /**
     * 执行命令
     *
     * @access public
     * @param Input $input 已解析参数
     * @param Output $output 输出
     * @return int
     */
    public function execute(Input $input,Output $output): int {
        $host=(string)$input->option('host','H',$this->config->get('console.serve.host','localhost'));
        $port_raw=$input->option('port','p',$this->config->get('console.serve.port',8000));
        $docroot=(string)$input->option('docroot','t',$this->config->get('console.serve.docroot',__DIR__.'/../../../../public'));
        $php=(string)$input->option('php',null,PHP_BINARY);
        $router=$input->option('router',null,null);
        if(preg_match('/^[0-9]+$/',(string)$port_raw)!==1
            ||(int)$port_raw<1
            ||(int)$port_raw>65535) {
            $output->error('[ERROR] 端口无效(Invalid port): '.(string)$port_raw.' (应为 1..65535)');
            return self::INVALID;
        }
        $port=(int)$port_raw;
        if(!is_dir($docroot)) {
            $output->error('[ERROR] 文档根目录不存在(Document root does not exist): '.$docroot);
            return self::INVALID;
        }
        if($router!==null&&$router!==false) {
            if(!is_string($router)||!is_file($router)) {
                $output->error('[ERROR] 路由脚本不存在(Router script does not exist): '.(string)$router);
                return self::INVALID;
            }
        }
        if($php==='')
            $php='php';
        $server_docroot=rtrim($docroot,'/\\');
        if($server_docroot==='')
            $server_docroot=$docroot;
        elseif(preg_match('/^[A-Za-z]:[\\\\\\/]$/',$docroot)===1)
            $server_docroot=$docroot;
        $command=escapeshellarg($php)
            .' -S '.escapeshellarg($host.':'.$port)
            .' -t '.escapeshellarg($server_docroot);
        if($router!==null&&$router!==false)
            $command.=' '.escapeshellarg((string)$router);
        $output->info('服务地址(Server): http://'.$host.':'.$port);
        $output->info('文档根目录(Document root): '.$docroot);
        $output->info('PHP: '.$php);
        $output->info('命令(Command): '.$command);
        if($input->hasOption('dry-run')) {
            $output->info('试运行(Dry run): 服务未启动(server was not started)。');
            return self::SUCCESS;
        }
        $exit_code=0;
        passthru($command,$exit_code);
        return (int)$exit_code;
    }

}
