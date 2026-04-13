<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Parser;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\WebSocket\Compression\WebsocketCompressionContext;

final class Rfc6455FrameCompilerFactory implements WebsocketFrameCompilerFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    public function createFrameCompiler(
        bool $masked,
        ?WebsocketCompressionContext $compressionContext = null,
    ): Rfc6455FrameCompiler {
        return new Rfc6455FrameCompiler($masked, $compressionContext);
    }
}
