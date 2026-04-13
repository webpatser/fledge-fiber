<?php declare(strict_types=1);

namespace Fledge\Async;

trait ForbidCloning
{
    final protected function __clone()
    {
        throw new \Error(__CLASS__ . ' does not support cloning');
    }
}
