<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket;

enum WebsocketCount
{
    case BytesReceived;
    case BytesSent;
    case FramesReceived;
    case FramesSent;
    case MessagesReceived;
    case MessagesSent;
    case PingsReceived;
    case PingsSent;
    case PongsReceived;
    case PongsSent;
    case UnansweredPings;
}
