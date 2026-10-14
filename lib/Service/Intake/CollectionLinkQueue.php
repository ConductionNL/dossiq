<?php

/**
 * Dossiq Collection Link Queue
 *
 * Queues the link of a resident's dossier to the case it started, so the
 * request that wrote the case answers without waiting for it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\BackgroundJob\LinkCollectionToCaseJob;
use OCP\BackgroundJob\IJobList;

/**
 * Puts one dossier link on the job list.
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
 */
class CollectionLinkQueue {

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The job list.
	 */
	public function __construct(
		private readonly IJobList $jobList,
	) {
	}//end __construct()

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
	public function queue(string $register, string $caseId, string $collectionId, string $subjectRef): void {
		$this->jobList->add(
			LinkCollectionToCaseJob::class,
			[
				'register' => $register,
				'caseId' => $caseId,
				'collectionId' => $collectionId,
				'subjectRef' => $subjectRef,
			]
		);
	}//end queue()
}//end class
