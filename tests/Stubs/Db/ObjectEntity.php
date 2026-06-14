<?php

/**
 * Test stub for OpenRegister's ObjectEntity.
 *
 * Minimal surface needed by procest unit tests: the audit listener resolves an
 * ObjectEntity from OR's ObjectService and hands it to AuditTrailMapper. Only
 * the accessors the procest code touches are stubbed.
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
 * Stub of OpenRegister's ObjectEntity for unit tests.
 */
class ObjectEntity
{
    /**
     * Object UUID.
     *
     * @var string|null
     */
    private ?string $uuid = null;

    /**
     * Get the object UUID.
     *
     * @return string|null
     */
    public function getUuid(): ?string
    {
        return $this->uuid;
    }//end getUuid()

    /**
     * Set the object UUID.
     *
     * @param string|null $uuid The UUID
     *
     * @return void
     */
    public function setUuid(?string $uuid): void
    {
        $this->uuid = $uuid;
    }//end setUuid()
}//end class
