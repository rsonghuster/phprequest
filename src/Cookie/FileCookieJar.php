<?php

namespace EasyRequest\Cookie;

class FileCookieJar extends CookieJar
{
    protected $file;

    public function __construct($file)
    {
        $this->file = $file;

        $this->load();
    }

    private function load()
    {
        if (file_exists($this->file)) {
            $array = (array) json_decode(file_get_contents($this->file), true);

            foreach ($array as $c) {
                $cookie = Cookie::parse($c);
                $this->add($cookie);
            }
        }
    }

    public function __destruct()
    {
        $cookies = array();
        foreach ($this->cookies as $c) {
            $cookies[] = $c->toArray();
        }

        file_put_contents($this->file, json_encode($cookies));
    }
}
