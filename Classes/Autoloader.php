<?php

/**
 * Date: 09.07.15
 * Time: 14:13
 */
class Autoloader
{

    public static function load($name)
    {
        try {
            $name = trim($name, '\\');
            $path = explode('\\', $name);
            $className = array_pop($path);
            $nameSpace = implode('/', $path);
            $file = $nameSpace.'/'.$className.'.php';
            if (file_exists('./'.$file)) {
                require_once './'.$file;
            } else {
                if (file_exists('./Vendors/'.$file)) {
                    require_once './Vendors/'.$file;
                }
            }
        } catch (Exception $e) {
            print $e->getMessage();
        }
    }
}