<?php
/**
 * Date: 09.07.15
 * Time: 11:23
 */
error_reporting(-1);
ini_set('display_errors', 'on');

date_default_timezone_set('Europe/Moscow');
header('Content-Type: text/html; charset=utf-8');
putenv('LC_ALL=ru_RU.utf8');
setlocale(LC_ALL, 'ru_RU.utf8');

require_once './Classes/Autoloader.php';
spl_autoload_register(array('Autoloader', 'load'));

$telegram = new \Classes\Telegram('116771656:AAFpe6XY6MzSpR_JGRmzbRcxkBxXhrUUg88');
$telegram->getUpdates();