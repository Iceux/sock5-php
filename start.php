<?php
require 'vendor/autoload.php';
use Liubinzh\Sock5\Sock5Server;

$s = new Sock5Server();
$s->start();