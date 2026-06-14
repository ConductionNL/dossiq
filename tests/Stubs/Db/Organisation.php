<?php

/**
 * Test stub for OpenRegister's Organisation entity.
 *
 * Minimal surface needed by procest unit tests: the tenant migration builds an
 * Organisation from a legacy tenant row. Only the accessors the procest code
 * touches are stubbed.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub of OpenRegister's Organisation for unit tests.
 */
class Organisation
{
    /** @var string|null */
    private ?string $uuid = null;

    /** @var string|null */
    private ?string $slug = null;

    /** @var string|null */
    private ?string $name = null;

    /** @var string|null */
    private ?string $status = 'active';

    /** @var array<int, string> */
    private array $groups = [];

    /** @var bool */
    private bool $active = true;

    /** @var int|null */
    private ?int $storageQuota = null;

    // phpcs:disable Squiz.Commenting.FunctionComment.Missing

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function setUuid(?string $uuid): void
    {
        $this->uuid = $uuid;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): void
    {
        $this->slug = $slug;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    /**
     * @return array<int, string>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @param array<int, string>|null $groups Groups list.
     */
    public function setGroups(?array $groups): void
    {
        $this->groups = ($groups ?? []);
    }

    public function getActive(): bool
    {
        return $this->active;
    }

    public function setActive(mixed $active): void
    {
        $this->active = (bool) $active;
    }

    public function getStorageQuota(): ?int
    {
        return $this->storageQuota;
    }

    public function setStorageQuota(?int $storageQuota): void
    {
        $this->storageQuota = $storageQuota;
    }

    // phpcs:enable
}//end class
