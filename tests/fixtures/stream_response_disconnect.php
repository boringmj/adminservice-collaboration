<?php

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Config\Repository;
use AdminService\HttpRequest;
use AdminService\Response;
use AdminService\ResponseProcessor\Http;
use AdminService\exception\ClientDisconnectedException;

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
$response=new Response($config);
$response->stream(function(callable $write): void {
    $write('partial');
    throw new ClientDisconnectedException('client gone');
},'text/plain');
$response->prepare(new HttpRequest(array('server'=>array(
    'REQUEST_URI'=>'/',
    'REQUEST_METHOD'=>'GET'
))));
$response->send();
