<?php

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Config\Repository;
use AdminService\HttpRequest;
use AdminService\Response;
use AdminService\ResponseProcessor\Http;

use function ignore_user_abort;

$config=new Repository(array(
    'response'=>array('default'=>array(
        'negotiation'=>'off',
        'type'=>array(
            'text/plain'=>array(
                'class'=>Http::class,
                'headers'=>array('Content-Type'=>'text/plain; charset=utf-8')
            )
        )
    ))
));
$request=new HttpRequest(array('server'=>array(
    'REQUEST_URI'=>'/',
    'REQUEST_METHOD'=>'GET'
)));
$run=static function(array $options) use ($config,$request): array {
    $inside=null;
    $response=new Response($config);
    $response->stream(function(callable $write) use (&$inside): void {
        $inside=ignore_user_abort();
        $write('.');
    },'text/plain',$options);
    $response->prepare($request);
    ignore_user_abort(false);
    $response->send();
    return array($inside,ignore_user_abort());
};
list($stop_inside,$stop_after)=$run(array());
list($continue_inside,$continue_after)=$run(array('on_disconnect'=>'continue'));
echo "\nstop={$stop_inside}/{$stop_after} continue={$continue_inside}/{$continue_after}\n";
