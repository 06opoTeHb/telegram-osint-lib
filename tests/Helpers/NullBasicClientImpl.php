<?php

declare(strict_types=1);

namespace Helpers;

use TelegramOSINT\Client\BasicClient\BasicClientImpl;
use TelegramOSINT\Exception\TGException;
use TelegramOSINT\TGConnection\DataCentre;
use TelegramOSINT\TGConnection\Socket\Socket;
use TelegramOSINT\TGConnection\SocketMessenger\SocketMessenger;
use TelegramOSINT\Tools\Proxy;

class NullBasicClientImpl extends BasicClientImpl
{
    /** @var array */
    private $traceArray;
    private $socketMessenger;

    public function __construct(array $traceArray)
    {
        parent::__construct();
        $this->traceArray = $traceArray;
    }

    protected function pickSocket(DataCentre $dc, Proxy $proxy = null, callable $cb = null): Socket
    {
        return new NullSocket();
    }

    /**
     * @throws TGException
     *
     * @return SocketMessenger
     */
    protected function getSocketMessenger(): SocketMessenger
    {
        if (!$this->socketMessenger) {
            $this->socketMessenger = new TraceSocketMessenger($this->traceArray, $this->getAuthKey(), $this);
        }

        return $this->socketMessenger;
    }
}
