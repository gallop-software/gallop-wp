<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

namespace Gallop\PostTypes;

final class Definition
{
    private const SUPPORTS = ['title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'custom-fields'];

    public function __construct(
        public readonly string $slug,
        public readonly string $singular,
        public readonly string $plural,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            slug:     (string)($data['slug'] ?? ''),
            singular: trim((string)($data['singular'] ?? '')),
            plural:   trim((string)($data['plural'] ?? '')),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'slug'     => $this->slug,
            'singular' => $this->singular,
            'plural'   => $this->plural,
        ];
    }

    /** @return array<string, mixed> */
    public function toRegisterArgs(): array
    {
        return [
            'labels' => [
                'name'          => $this->plural,
                'singular_name' => $this->singular,
            ],
            'public'       => true,
            'show_in_rest' => true,
            'rest_base'    => $this->slug,
            'supports'     => self::SUPPORTS,
            'taxonomies'   => ['post_tag'],
            'has_archive'  => false,
            'menu_position' => 20,
        ];
    }

    public function isValid(): bool
    {
        return $this->slug !== '' && $this->singular !== '' && $this->plural !== '';
    }
}
