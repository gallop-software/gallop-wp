<?php

declare(strict_types=1);

namespace Gallop\PostTypes;

if (!defined('ABSPATH')) {
    exit;
}

final class Storage
{
    private const OPTION = 'gallop_post_types';

    /** @return list<Definition> */
    public function all(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) {
            return [];
        }

        $defs = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $def = Definition::fromArray($item);
            if ($def->isValid()) {
                $defs[] = $def;
            }
        }
        return $defs;
    }

    public function find(string $slug): ?Definition
    {
        foreach ($this->all() as $def) {
            if ($def->slug === $slug) {
                return $def;
            }
        }
        return null;
    }

    public function save(Definition $def): void
    {
        $defs = array_filter($this->all(), static fn(Definition $d): bool => $d->slug !== $def->slug);
        $defs[] = $def;
        $this->persist($defs);
    }

    public function delete(string $slug): void
    {
        $defs = array_filter($this->all(), static fn(Definition $d): bool => $d->slug !== $slug);
        $this->persist($defs);
    }

    /** @param iterable<Definition> $defs */
    private function persist(iterable $defs): void
    {
        $out = [];
        foreach ($defs as $def) {
            $out[] = $def->toArray();
        }
        update_option(self::OPTION, $out);
    }
}
