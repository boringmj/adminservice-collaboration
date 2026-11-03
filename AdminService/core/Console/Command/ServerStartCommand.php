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
            $output->error('[ERROR] Invalid port: '.(string)$port_raw.' (expected 1..65535)');
            return self::INVALID;
        }
        $port=(int)$port_raw;
        if(!is_dir($docroot)) {
            $output->error('[ERROR] Document root does not exist: '.$docroot);
            return self::INVALID;
        }
        if($router!==null&&$router!==false) {
            if(!is_string($router)||!is_file($router)) {
                $output->error('[ERROR] Router script does not exist: '.(string)$router);
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
        $output->info('Server: http://'.$host.':'.$port);
        $output->info('Document root: '.$docroot);
        $output->info('PHP: '.$php);
        $output->info('Command: '.$command);
        if($input->hasOption('dry-run')) {
            $output->info('Dry run: server was not started.');
            return self::SUCCESS;
        }
        $exit_code=0;
        passthru($command,$exit_code);
        return (int)$exit_code;
    }

}
