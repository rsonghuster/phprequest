<?php

namespace EasyRequest\Cookie;

use Psr\Http\Message\ResponseInterface;

class CookieJar implements CookieJarInterface
{
    protected $cookies = array();

    /**
     * {@inheritdoc}
     */
    public function add(Cookie $cookie, $defaultDomain = null, $defaultPath = null)
    {
        $exists = false;
        foreach ($this->cookies as $key => $c) {
            if ($c->expires && $c->expires < time()) {
                unset($this->cookies[$key]);
                continue;
            }
            if ($c->name == $cookie->name
                && ltrim($c->domain, '.') == ltrim($cookie->domain, '.')
                && $c->path == $cookie->path
            ) {
                if ($c->value != $cookie->value
                    || $c->expires < $cookie->expires
                ) {
                    unset($this->cookies[$key]);
                    continue;
                }
                $exists = true;
                break;
            }
        }
        if (! $exists && $cookie->name) {
            if (! $cookie->domain) {
                $cookie->setDomain($defaultDomain);
            }
            if (! $cookie->path) {
                $cookie->setPath($defaultPath);
            }
            $this->cookies[] = $cookie;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addCookies(array $cookies, $defaultDomain = null, $defaultPath = null)
    {
        foreach ($cookies as $cookie) {
            if ($cookie instanceof Cookie) {
                $this->add($cookie, $defaultDomain, $defaultPath);
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function fromString($str, $defaultDomain = null, $defaultPath = null)
    {
        foreach (explode(';', $str) as $s) {
            $this->add(Cookie::parse($s), $defaultDomain, $defaultPath);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function fromResponse(ResponseInterface $response, $defaultDomain = null, $defaultPath = null)
    {
        $defaultDomain = $defaultDomain ? $defaultDomain : $response->getHeaderLine('Host');

        foreach ($response->getHeader('Set-Cookie') as $c) {
            $cookie = Cookie::parse($c);

            if (! $cookie->expires && $cookie->maxage) {
                $cookie->setExpires(time() + $cookie->maxage);
            }

            $this->add($cookie, $defaultDomain, $defaultPath);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getFor($domain, $path = null)
    {
        $cookies = array();
        foreach ($this->cookies as $key => $cookie) {
            if ($cookie->expires && $cookie->expires < time()) {
                unset($this->cookies[$key]);
                continue;
            }
            if (($domain === null || $this->isCookieMatchesDomain($cookie, $domain))
                && ($path === null || $this->isCookieMatchesPath($cookie, $path))
                && (! $cookie->expires || $cookie->expires >= time())
            ) {
                $cookies[] = $cookie;
            }
        }

        $jar = new self;
        $jar->cookies = $cookies;

        return $jar;
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        return count($this->cookies);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        return $this->cookies;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        $out = '';
        foreach ($this->cookies as $cookie) {
            $out .= $cookie.' ';
        }

        return trim($out);
    }

    /**
     * Check if the cookie matches a path value.
     *
     * @param string $path
     *
     * @return bool
     */
    protected function isCookieMatchesPath(Cookie $cookie, $path)
    {
        return empty($cookie->path) || strpos($path, $cookie->path) === 0;
    }

    /**
     * Check if the cookie matches a domain value.
     *
     * @param string $domain
     *
     * @return bool
     */
    protected function isCookieMatchesDomain(Cookie $cookie, $domain)
    {
        // Remove the leading '.' as per spec in RFC 6265.
        // http://tools.ietf.org/html/rfc6265#section-5.2.3
        $cookieDomain = isset($cookie->domain) ? ltrim($cookie->domain, '.') : null;

        // Domain not set or exact match.
        if (! $cookieDomain || ! strcasecmp($domain, $cookieDomain)) {
            return true;
        }

        // Matching the subdomain according to RFC 6265.
        // http://tools.ietf.org/html/rfc6265#section-5.1.3
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return false;
        }

        return (bool) preg_match('/\.'.preg_quote($cookieDomain).'$/i', $domain);
    }
}
