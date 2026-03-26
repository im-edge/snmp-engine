<?php

namespace IMEdge\SnmpEngine\Dispatcher;

use Amp\DeferredFuture;
use IMEdge\SnmpEngine\Error\SnmpTimeoutError;
use IMEdge\SnmpEngine\Timeout\TimeoutHandler;
use IMEdge\SnmpPacket\Pdu\Pdu;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;

class OutgoingRequestHandler implements RequestIdConsumer
{
    protected int $requestTimeout = 3;

    /** @var array<int, DeferredFuture<Pdu>> */
    protected array $pendingRequests = [];
    protected RequestIdGenerator $idGenerator;
    protected TimeoutHandler $timeout;

    public function __construct()
    {
        $this->idGenerator = new RandomRequestIdGenerator();
        $this->idGenerator->registerConsumer($this);
        $this->timeout = new TimeoutHandler($this->triggerTimeout(...));
    }

    protected function triggerTimeout(int $requestId): void
    {
        $this->reject($requestId, new SnmpTimeoutError("Timeout for request id=$requestId"));
    }

    /**
     * @return DeferredFuture<Pdu>
     */
    public function schedulePdu(Pdu $pdu): DeferredFuture
    {
        $id = $pdu->requestId;
        if ($id === null) {
            $pdu->requestId = $id = $this->idGenerator->getNextId();
        } else {
            if (isset($this->pendingRequests[$id])) {
                throw new RuntimeException(sprintf('Request ID %s is already pending', $id));
            }
        }
        $this->pendingRequests[$id] = $deferred = new DeferredFuture();
        // print_r(array_keys($this->pendingRequests));
        $this->timeout->schedule($id, $this->requestTimeout);

        return $deferred;
    }

    /**
     * @return DeferredFuture<Pdu>|null
     */
    public function complete(?int $id): ?DeferredFuture
    {
        if ($id === null) {
            return null;
        }
        $this->timeout->forget($id);
        $deferred = $this->pendingRequests[$id] ?? null;
        unset($this->pendingRequests[$id]);
        return $deferred;
    }

    public function rejectAll(Throwable $error): void
    {
        foreach ($this->listPendingIds() as $id) {
            $this->reject($id, $error);
        }
    }

    public function reject(int $id, Throwable $error): void
    {
        $deferred = $this->pendingRequests[$id] ?? null;
        if ($deferred === null) {
            // TODO: Log printf("Failed to reject %d with '%s', it's gone\n", $id, $error->getMessage());
            return;
        }
        unset($this->pendingRequests[$id]);
        $this->timeout->forget($id);
        EventLoop::defer(fn () => $deferred->error($error));
    }

    /**
     * @return int[]
     */
    protected function listPendingIds(): array
    {
        return array_keys($this->pendingRequests);
    }

    public function hasId(int $id): bool
    {
        return isset($this->pendingRequests[$id]);
    }

    public function __destruct()
    {
        unset($this->timeoutTimer);
    }
}
