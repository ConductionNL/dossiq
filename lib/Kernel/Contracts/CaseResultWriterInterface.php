<?php

/**
 * Contract of CaseResultWriter
 *
 * The public surface of CaseResultWriter that other components call, so they depend
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
 * Contract of CaseResultWriter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseResultWriterInterface {

	/**
	 * Answer whether a statusType is a terminal one.
	 *
	 * A statusType that cannot be read answers false. The asymmetry is
	 * deliberate: a false negative only means the result question goes
	 * unasked, while a false positive would refuse an ordinary transition on
	 * a row nobody could read.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return bool True when the statusType carries isFinal.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isFinalStatus(string $statusTypeId): bool;
}//end interface
