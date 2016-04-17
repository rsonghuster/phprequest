<?php

namespace EasyRequest\Handler;

use Psr\Http\Message\RequestInterface;

interface HandlerInterface
{
    /**
     * Send request.
     *
     * @param  RequestInterface                    $request
     * @param  array                               $options
     * @throws \Exception                          if send failure
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function send(RequestInterface $request, array $options = array());
}
