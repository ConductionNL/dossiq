<?php

/**
 * Test stub for OpenRegister's AuditTrailMapper.
 *
 * Minimal surface needed by procest unit tests: the parafering audit listener
 * calls createAuditTrailEntry(ObjectEntity, string, array). The stub records
 * the arguments so the test can assert on them. The real OR implementation
 * persists a hash-chained, append-only audit-trail row.
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
 * Stub of OpenRegister's AuditTrailMapper for unit tests.
 */
class AuditTrailMapper
{
    /**
     * Create a custom audit trail entry.
     *
     * @param ObjectEntity         $object  The object the entry relates to
     * @param string               $action  The action string
     * @param array<string, mixed> $context Additional context data
     *
     * @return object A lightweight audit-trail-like object
     */
    public function createAuditTrailEntry(ObjectEntity $object, string $action, array $context = []): object
    {
        return (object) [
            'objectUuid' => $object->getUuid(),
            'action'     => $action,
            'changed'    => $context,
        ];
    }//end createAuditTrailEntry()
}//end class
