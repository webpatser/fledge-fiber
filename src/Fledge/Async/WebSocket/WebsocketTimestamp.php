<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket;

enum WebsocketTimestamp
{
    case Connected;
    case Closed;
    case LastRead;
    case LastSend;
    case LastDataRead;
    case LastDataSend;
    case LastHeartbeat;
}
