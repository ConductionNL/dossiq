<?php

/**
 * Contract of RemedyLodging
 *
 * The public surface of RemedyLodging that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of RemedyLodging.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RemedyLodgingInterface {

	/**
	 * Lodge a remedy against the decision of a decided case.
	 *
	 * @param string               $caseId    The decided case.
	 * @param array<string, mixed> $objection The objection fields (receivedDate, receivedChannel, grounds, ...).
	 *
	 * @return array{caseId: string, objectionId: string, deadline: string, isTimely: bool}
	 *
	 * @throws RefusedException When the case has no decision or declares no remedy case type.
	 * @throws RuntimeException When OpenRegister is unavailable or the objection cannot be recorded.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-lodged-remedy-is-judged-timely-against-the-declared-term-req-rml-002
	 */
	public function lodge(string $caseId, array $objection): array;
}//end interface
