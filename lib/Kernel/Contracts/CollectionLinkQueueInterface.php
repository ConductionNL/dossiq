<?php

/**
 * Contract of CollectionLinkQueue
 *
 * The public surface of CollectionLinkQueue that other components call, so they depend
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
 * Contract of CollectionLinkQueue.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CollectionLinkQueueInterface {

	/**
	 * Queue the link of a dossier to a case.
	 *
	 * @param string $register     The dossiq register.
	 * @param string $caseId       The case.
	 * @param string $collectionId The dossier, already proven the resident's.
	 * @param string $subjectRef   The resident.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
	 */
	public function queue(string $register, string $caseId, string $collectionId, string $subjectRef): void;
}//end interface
