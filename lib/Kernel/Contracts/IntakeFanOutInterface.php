<?php

/**
 * Contract of IntakeFanOut
 *
 * The public surface of IntakeFanOut that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of IntakeFanOut.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeFanOutInterface {

	/**
	 * Open one case per declared destination, and tie them together.
	 *
	 * @param string               $formCaseTypeId The intake case type the form maps to.
	 * @param array<string, mixed> $submission     The submitted values every case starts from.
	 * @param string               $submissionId   The submission's own identifier.
	 *
	 * @return array{created: array<int, array<string, mixed>>, failed: array<int, array<string, string>>}
	 *
	 * @throws RefusedException When nothing is declared or the register is absent.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function submit(string $formCaseTypeId, array $submission, string $submissionId): array;
}//end interface
