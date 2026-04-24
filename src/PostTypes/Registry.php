<?php

declare(strict_types=1);

namespace Gallop\PostTypes;

final class Registry
{
    public function __construct(private readonly Storage $storage)
    {
    }

    public function registerAll(): void
    {
        foreach ($this->storage->all() as $def) {
            register_post_type($def->slug, $def->toRegisterArgs());
        }
    }
}
