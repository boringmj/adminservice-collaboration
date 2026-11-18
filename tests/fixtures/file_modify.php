<?php

/**
 * 跨进程修改文件夹具
 *
 * - 供 stat 缓存测试: 同进程修改会自动清 stat 缓存, 必须经另一进程修改
 */

file_put_contents($argv[1],'BBBB');
touch($argv[1],(int)$argv[2]);
