<?php
/**
 * Date: 09.07.15
 * Time: 16:26
 */

namespace Classes;

class Telegram
{

    const API_BOT_URL = 'https://api.telegram.org/bot';

    protected $api_key;
    protected $url;
    protected $client;

    public function __construct($api_key)
    {
        $this->url = self::API_BOT_URL.$api_key.'/';
    }

    public function getUpdates()
    {
        $response = $this->call('getUpdates');
        $json = json_decode((string)$response->getBody());
        var_dump($json);
    }

    protected function call($method, $data = array())
    {
        return Request::init()->post($this->url.$method, $data);
    }

}