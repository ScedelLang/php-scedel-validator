<?php

declare(strict_types=1);

namespace Scedel\Validator\Internal;

final readonly class ValidationScope
{
    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public mixed $root,
        public mixed $current,
        public mixed $parent,
        public array $variables = [],
    ) {
    }

    public function child(mixed $current): self
    {
        return new self(
            root: $this->root,
            current: $current,
            parent: $this->current,
            variables: $this->variables,
        );
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function withVariables(array $variables): self
    {
        return new self(
            root: $this->root,
            current: $this->current,
            parent: $this->parent,
            variables: $variables,
        );
    }
}
