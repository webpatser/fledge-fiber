<?php

namespace Fledge\Fiber\Database\Connections;

use Fiber;
use Illuminate\Database\MySqlConnection;
use Revolt\EventLoop\FiberLocal;

/**
 * Keeps Laravel's connection-level `$lastInsertId` per fiber.
 *
 * MySqlConnection::insert() stores the id on the connection and
 * MySqlProcessor::processInsertGetId() reads it back after run() fired
 * QueryExecuted. A listener that suspends in between would let another fiber
 * insert on the same connection object and overwrite the id, so
 * insertGetId() would return the other fiber's row. The id lives in a
 * FiberLocal (Revolt clears it after every callback); the main context uses
 * the backing slot. Same pattern as the native drivers' UsesNativePdoPool.
 *
 * @mixin MySqlConnection
 */
trait KeepsLastInsertIdPerFiber
{
    /** @var FiberLocal<string|int|false|null> */
    private FiberLocal $fiberLastInsertIds;

    /**
     * The last insert id of the current fiber (or the main context, which
     * uses the backing slot).
     *
     * @var string|int|null
     */
    protected $lastInsertId = null {
        get {
            $fiber = Fiber::getCurrent();

            return $fiber === null ? $this->lastInsertId : ($this->fiberLastInsertIds ?? null)?->get();
        }
        set {
            $fiber = Fiber::getCurrent();

            if ($fiber === null) {
                $this->lastInsertId = $value;

                return;
            }

            $this->fiberLastInsertIds ??= new FiberLocal(static fn () => null);
            $this->fiberLastInsertIds->set($value);
        }
    }

    /**
     * A clone starts without insert ids of other fibers.
     */
    public function __clone()
    {
        parent::__clone();

        unset($this->fiberLastInsertIds);
    }
}
