<?php
/**
 * Date: 09.07.15
 * Time: 17:06
 */

namespace Classes;

use GuzzleHttp;

class Request
{
    protected static $_instance;
    protected $client;

    private function __construct()
    {
        require __DIR__.'/../vendor/autoload.php';
        $this->client = new \GuzzleHttp\Client();
    }

    public static function init()
    {
        if (!isset(self::$_instance) || self::$_instance === null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function get($url, $options = array(), $headers = array())
    {
        $response = $this->client->get($url, $options);

        return $response;
    }

    public function post($url, $options = array(), $headers = array())
    {
        $response = $this->client->post($url, $options);

        return $response;
    }

}