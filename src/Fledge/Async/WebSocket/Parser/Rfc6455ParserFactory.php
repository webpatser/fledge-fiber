<?php declare(strict_types=1);

namespace Fledge\Async\WebSocket\Parser;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\WebSocket\Compression\WebsocketCompressionContext;

final class Rfc6455ParserFactory implements WebsocketParserFactory
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly bool $textOnly = Rfc6455Parser::DEFAULT_TEXT_ONLY,
        private readonly bool $validateUtf8 = Rfc6455Parser::DEFAULT_VALIDATE_UTF8,
        private readonly int $messageSizeLimit = Rfc6455Parser::DEFAULT_MESSAGE_SIZE_LIMIT,
        private readonly int $frameSizeLimit = Rfc6455Parser::DEFAULT_FRAME_SIZE_LIMIT,
    ) {
    }

    public function createParser(
        WebsocketFrameHandler $frameHandler,
        bool $masked,
        ?WebsocketCompressionContext $compressionContext = null,
    ): Rfc6455Parser {
        return new Rfc6455Parser(
            $frameHandler,
            $masked,
            $compressionContext,
            $this->textOnly,
            $this->validateUtf8,
            $this->messageSizeLimit,
            $this->frameSizeLimit,
        );
    }
}
