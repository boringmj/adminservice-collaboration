<?php

namespace AdminService\Console\Command;

use AdminService\Application;
use AdminService\Config\Repository;
use AdminService\Console\Input;
use AdminService\Console\Output;
use AdminService\Worker\SwooleDriver;
use AdminService\Worker\Worker;
use base\AbstractCommand;
use Throwable;

use function class_exists;
use function extension_loaded;
use function preg_match;

/**
 * 启动常驻 worker
 *
 * @access public
 * @package AdminService\Console\Command
 * @version 1.0.0
 */
final class WorkerStartCommand extends AbstractCommand {

    /**
     * 配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 已启动的应用
     * @var Application
     */
    private Application $application;

    /**
     * 构造方法
     *
     * @access public
     * @param Repository $config 配置仓储
     * @param Application $application 已启动的应用
     */
    public function __construct(Repository $config,Application $application) {
        $this->config=$config;
        $this->application=$application;
    }

    /**
     * 规范命令路径
     *
     * @access public
     * @return string
     */
    public function name(): string {
        return 'worker start';
    }

    /**
     * 命令说明
     *
     * @access public
     * @return string
     */
    public function description(): string {
        return '启动常驻 worker(Swoole)';
    }

    /**
     * 需要接收值的选项
     *
     * @access public
     * @return array<int,string>
     */
    public function valueOptions(): array {
        return array('host','H','port','p','workers','w');
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
            array('names'=>array('host','H'),'value'=>'地址','description'=>'监听地址, 默认 worker.host'),
            array('names'=>array('port','p'),'value'=>'端口','description'=>'监听端口, 默认 worker.port'),
            array('names'=>array('workers','w'),'value'=>'数量','description'=>'进程数, 默认 worker.workers'),
            array('names'=>array('dry-run'),'value'=>null,'description'=>'只输出配置, 不启动服务'),
        );
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'worker start [--host=HOST] [--port=PORT] [--workers=N] [--dry-run]';
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
        $host=(string)$input->option('host','H',$this->config->get('worker.host','127.0.0.1'));
        $port_raw=$input->option('port','p',$this->config->get('worker.port',9501));
        $workers_raw=$input->option('workers','w',$this->config->get('worker.workers',1));
        if(preg_match('/^[0-9]+$/',(string)$port_raw)!==1
            ||(int)$port_raw<1
            ||(int)$port_raw>65535) {
            $output->error('[ERROR] 端口无效(Invalid port): '.(string)$port_raw.' (应为 1..65535)');
            return self::INVALID;
        }
        if(preg_match('/^[0-9]+$/',(string)$workers_raw)!==1||(int)$workers_raw<1) {
            $output->error('[ERROR] 进程数无效(Invalid workers): '.(string)$workers_raw);
            return self::INVALID;
        }
        $port=(int)$port_raw;
        $workers=(int)$workers_raw;
        $compression=(bool)$this->config->get('worker.compression',false);
        $compression_level=(int)$this->config->get('worker.compression_level',4);
        if($compression&&($compression_level<1||$compression_level>9)) {
            $output->error('[ERROR] 压缩级别无效(Invalid compression level): '.$compression_level.' (应为 1..9)');
            return self::INVALID;
        }
        $swoole=extension_loaded('swoole')&&class_exists('Swoole\\Http\\Server');
        $output->info('监听(Worker): '.$host.':'.$port);
        $output->info('进程数(Workers): '.$workers);
        $output->info('压缩(Compression): '.($compression?'gzip level '.$compression_level:'off'));
        $output->info('会话驱动(Session): '.(string)$this->config->get('session.class',''));
        $output->info('Swoole: '.($swoole?'可用(available)':'不可用(unavailable)'));
        if($input->hasOption('dry-run')) {
            $output->info('试运行(Dry run): worker 未启动(worker was not started)。');
            return self::SUCCESS;
        }
        if(PHP_SAPI!=='cli') {
            $output->error('[ERROR] worker 只支持 CLI SAPI');
            return self::FAILURE;
        }
        if(!$swoole) {
            $output->error('[ERROR] 需要 Swoole 扩展: pecl install swoole 或从源码编译');
            return self::FAILURE;
        }
        try {
            $worker=new Worker($this->application);
            $worker->boot();
            $output->info('启动(Start): worker 正在运行, 按 Ctrl+C 停止');
            (new SwooleDriver($worker))->serve($host,$port,$workers,$compression,$compression_level);
        } catch(Throwable $e) {
            $output->error('[ERROR] worker 启动失败(Worker start failed): '.$e->getMessage());
            return self::FAILURE;
        }
        $output->info('停止(Stop): worker 已退出');
        return self::SUCCESS;
    }

}
