<?php

/**
 * Contract of CaseFieldRoleProjector
 *
 * The public surface of CaseFieldRoleProjector that other components call, so they depend
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

/**
 * Contract of CaseFieldRoleProjector.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseFieldRoleProjectorInterface {

	/**
	 * Put the ledger's grants back onto the properties.
	 *
	 * Called after the register import, which rewrites a schema's `properties`
	 * from the register JSON and therefore drops every grant this class
	 * projected. Nothing errors when that happens: the field simply starts
	 * being returned to the role it was withheld from, on every read, until
	 * somebody happens to publish the case type again. That is the silent
	 * disclosure this method exists to close, and it is why it runs on every
	 * reconcile rather than on a version gate.
	 *
	 * @return bool True when the schema was rewritten.
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function reapply(): bool;
}//end interface
