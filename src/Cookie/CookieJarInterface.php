<?php

namespace EasyRequest\Cookie;

use Psr\Http\Message\ResponseInterface;

interface CookieJarInterface
{
    /**
     * Add cookie to jar.
     *
     * @param  Cookie      $cookie
     * @param  null|string $defaultDomain Default domain if not set
     * @param  null|string $defaultPath   Default path if not set
     * @return self
     */
    public function add(Cookie $cookie, $defaultDomain = null, $defaultPath = null);

    /**
     * Add cookies from array.
     *
     * @param  \EasyRequest\Cookie\Cookie[] $cookies
     * @param  null|string                  $defaultDomain Default domain if not set
     * @param  null|string                  $defaultPath   Default path if not set
     * @return self
     */
    public function addCookies(array $cookies, $defaultDomain = null, $defaultPath = null);

    /**
     * Add cookies from string.
     *
     * @param  string      $str
     * @param  null|string $defaultDomain Default domain if not set
     * @param  null|string $defaultPath   Default path if not set
     * @return self
     */
    public function fromString($str, $defaultDomain = null, $defaultPath = null);

    /**
     * Add cookies from response headers Set-Cookie.
     *
     * @param  array       $cookies
     * @param  null|string $defaultDomain Default domain if not set
     * @param  null|string $defaultPath   Default path if not set
     * @return self
     */
    public function fromResponse(ResponseInterface $response, $defaultDomain = null, $defaultPath = null);

    /**
     * Returns new instance contains array of cookies matched given domain and path.
     * If $path is null, return all domains matched given domain name.
     *
     * @param  string      $domain
     * @param  null|string $path
     * @return self
     */
    public function getFor($domain, $path = null);

    /**
     * Returns count cookies in the stack.
     *
     * @return int
     */
    public function count();

    /**
     * Returns array of cookies in the stack.
     *
     * @return array
     */
    public function toArray();

    /**
     * Returns string that can be added to Cookie header.
     *
     * @return string
     */
    public function __toString();
}
