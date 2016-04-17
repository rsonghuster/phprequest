<?php

namespace EasyRequest\Cookie;

class SessionCookieJar extends CookieJar
{
    protected $key;

    public function __construct($key = __CLASS__)
    {
        $this->key = $key;

        $this->load();
    }

    private function load()
    {
        if (isset($_SESSION[$this->key]) && is_array($_SESSION[$this->key])) {
            $array = $_SESSION[$this->key];

            $this->addCookies((array) $array);
        }
    }

    public function __destruct()
    {
        $_SESSION[$this->key] = $this->cookies;
    }
}
