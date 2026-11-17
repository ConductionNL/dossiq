<?php

/**
 * Contract of DocumentJoinHoming
 *
 * The public surface of DocumentJoinHoming that other components call, so they depend
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
 * Contract of DocumentJoinHoming.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentJoinHomingInterface {

	/**
	 * Move the document's file into the case's folder, when it is not yet in any.
	 *
	 * A failure here is logged, not thrown: the join exists, the file is where
	 * it was, and the next join or the repair step moves it.
	 *
	 * @param string $caseUrl The zaak URL or uuid.
	 * @param string $informatieobjectUrl The informatieobject URL or uuid.
	 *
	 * @return bool True when the file moved.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function home(string $caseUrl, string $informatieobjectUrl): bool;

	/**
	 * Why a join to this case must be refused, or null when it may proceed.
	 *
	 * @param string $caseUrl The zaak URL or uuid the join names.
	 *
	 * @return string|null The refusal, naming the case.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function refusal(string $caseUrl): ?string;
}//end interface
