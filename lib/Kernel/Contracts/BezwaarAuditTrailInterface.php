<?php

/**
 * Contract of BezwaarAuditTrail
 *
 * The public surface of BezwaarAuditTrail that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException;

/**
 * Contract of BezwaarAuditTrail.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BezwaarAuditTrailInterface {

	/**
	 * The action prefix of every bezwaar entry.
	 */
	public const ACTION_PREFIX = 'dossiq.bezwaar.';

	/**
	 * Write a prepared context as a row on a record's audit trail.
	 *
	 * The repair step that copies the old arrays writes through here, with the
	 * original entry as context, so a copied row and a live one share one path.
	 *
	 * @param string               $register   The register the record lives in.
	 * @param string               $schema     The record's schema.
	 * @param string               $objectUuid The record's uuid.
	 * @param string               $action     The full action.
	 * @param array<string, mixed> $context    The row's context.
	 * @param string|null          $actorId    An explicit actor, or null for the session's.
	 *
	 * @return void
	 *
	 * @throws BezwaarEntryNotWrittenException When OpenRegister is absent, the record does not resolve, or the write fails.
	 *
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function write(string $register, string $schema, string $objectUuid, string $action, array $context, ?string $actorId = null): void;
}//end interface
