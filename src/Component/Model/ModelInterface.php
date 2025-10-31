<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Model;

interface ModelInterface
{
    public function getId(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
