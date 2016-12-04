<?php

namespace EasyRequest\Cookie;

class Cookie
{
    public $name;
    public $value;
    public $path = '/';
    public $domain;
    public $expires; // null|int
    public $maxage;
    public $discard;
    public $secure;
    public $httponly;

    /**
     * Parse cookie from string or array of attribute-value pairs.
     *
     * @param string|array $data
     * @return
     */
    public static function parse($data)
    {
        $cookie = new self;
        if (is_string($data)) {
            preg_match_all('#(?:^|\s*)([^=;]+)(?:=([^;]*)|;|$)?\s*#', $data, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                list(, $key, $value) = $match + array('', '', true);

                if (is_null($cookie->name) && is_string($value)) {
                    $cookie->name = $key;
                    $cookie->value = urldecode($value);
                } else {
                    $key = str_replace('-', '', strtolower($key));
                    if (! property_exists($cookie, $key)) {
                        continue;
                    }
                    if ($key == 'expires' && is_string($value)) {
                        $value = strtotime($value);
                    }
                    if (in_array($key, array('discard', 'httponly', 'secure'))) {
                        $value = true;
                    }
                    $cookie->{$key} = $value;
                }
            }
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                if (property_exists($cookie, $key)) {
                    $cookie->{$key} = $value;
                }
            }
        }

        return $cookie;
    }

    public function __call($method, $args)
    {
        if (preg_match('#^(get|set)(.*?)$#i', strtolower($method), $match)) {
            $prop = $match[2];

            if (! property_exists($this, $prop)) {
                throw new \InvalidArgumentException(sprintf('Property %s does not exist.', $prop));
            }

            if ($match[1] == 'get') {
                return $this->{$prop};
            }

            $this->{$prop} = $args[0];

            return;
        }
        throw new \Exception(sprintf('Call to undefined method %s::%s', __CLASS__, $method));
    }

    public function __toString()
    {
        return $this->name ? sprintf('%s=%s;', $this->name, urlencode($this->value)) : '';
    }

    public function toArray()
    {
        return json_decode(json_encode($this), true);
    }
}
