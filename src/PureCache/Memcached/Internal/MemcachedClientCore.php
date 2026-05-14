<?php

declare(strict_types=1);

namespace PureCache\Memcached\Internal;

use PureCache\Internal\ClientCoreState;
use PureCache\MemcachedConstants;

/**
 * Mutable state shared by {@see \PureCache\Memcached\MemcachedClient} instances
 * when using the same persistent_id (PECL-style in-process pooling).
 */
final class MemcachedClientCore extends ClientCoreState
{
    public ConnectionManager $conn;

    private function __construct()
    {
    }

    public static function createFresh(?string $persistentId = null): self
    {
        $c = new self();
        $c->initDefaults($persistentId);
        $c->rebuildConnectionManager();

        return $c;
    }

    public function rebuildConnectionManager(): void
    {
        if (isset($this->conn)) {
            $this->conn->closeAll();
        }

        $recvMs = $this->optionInt(MemcachedConstants::OPT_RECV_TIMEOUT, 0);
        $sendMs = $this->optionInt(MemcachedConstants::OPT_SEND_TIMEOUT, 0);

        $this->conn = new ConnectionManager(
            $this->selector,
            $this->optionInt(MemcachedConstants::OPT_CONNECT_TIMEOUT, 1000) / 1000,
            $recvMs > 0 ? $recvMs * 1000 : null,
            $sendMs > 0 ? $sendMs * 1000 : null,
            $this->persistentId,
            $this->optionBool(MemcachedConstants::OPT_TCP_NODELAY, false),
            $this->optionBool(MemcachedConstants::OPT_TCP_KEEPALIVE, false),
            $this->optionInt(MemcachedConstants::OPT_SOCKET_SEND_SIZE, 0),
            $this->optionInt(MemcachedConstants::OPT_SOCKET_RECV_SIZE, 0),
        );
    }
}
