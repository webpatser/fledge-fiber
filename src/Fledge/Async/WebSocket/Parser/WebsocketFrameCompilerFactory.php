<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Parser;

use Fledge\Async\WebSocket\Compression\WebsocketCompressionContext;

interface WebsocketFrameCompilerFactory
{
    public function createFrameCompiler(
        bool $masked,
        ?WebsocketCompressionContext $compressionContext = null,
    ): WebsocketFrameCompiler;
}
