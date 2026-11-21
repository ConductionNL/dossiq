<?php

/**
 * Contract of ConnectedSystemCaseWriter
 *
 * The public surface of ConnectedSystemCaseWriter that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ConnectedSystemCaseWriter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConnectedSystemCaseWriterInterface {

	/**
	 * The operation is understood and is not carried out.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * Carry out one operation, or say why not.
	 *
	 * @param array<string, mixed> $operation  The generic operation: `action` (create, update, add-document) and its fields.
	 * @param array<string, mixed> $connection What the connection allows: `acceptsWrites`, `caseTypes` (case type reference to the
	 *                                         system's name for it) and `documentTypes` (the system's name to a document type id).
	 * @param string               $system     The id of the connection the operation came in on.
	 *
	 * @return array The outcome (WriteOutcome): `identifier` when done, `refusal` and `kind` when not.
	 *
	 * @throws RuntimeException When the register is not configured or the store fails: nothing was decided.
	 *
	 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-connected-system-writes-as-the-service-account
	 */
	public function carryOut(array $operation, array $connection, string $system): array;
}//end interface
