<?php

/**
 * Dossiq link-collection-to-case job.
 *
 * Links a resident's dossier to the Woo request case it started, off the
 * request thread. The link is one OpenRegister read and one write per dossier
 * item plus a write on the dossier, which made the portal's forward wait past
 * its timeout while the case already existed (live round 3, 14 Oct: the
 * resident read a failure, sent again and got duplicate cases). The case is
 * written and answered first; the dossier's documents follow here.
 *
 * IT RUNS AS THE BACKGROUND SERVICE ACCOUNT, because cron has no user. Without
 * a usable account nothing is written and the same argument is queued again.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\Intake\CollectionCaseLinks;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Links one dossier to one case, once.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
 */
class LinkCollectionToCaseJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time            The time factory.
	 * @param SettingsService          $settingsService Register, schemas and OpenRegister.
	 * @param IURLGenerator            $urlGenerator    Makes the publication links absolute.
	 * @param IJobList                 $jobList         The job list, to queue the argument again.
	 * @param LoggerInterface          $logger          The logger.
	 * @param BackgroundServiceAccount $serviceAccount  The account the run writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settingsService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Link the dossier to the case, as the background service account.
	 *
	 * @param mixed $argument `{register, caseId, collectionId, subjectRef}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
	 */
	protected function run($argument): void {
		try {
			$this->serviceAccount->runAs(operation: fn () => $this->link(argument: (array)$argument));
		} catch (ServiceAccountUnavailableException $e) {
			// Nothing was written; a queued job is gone after this run, so the
			// same argument waits for the next one.
			$this->jobList->add(self::class, $argument);
		}
	}//end run()

	/**
	 * Link the dossier to the case; a dossier that is no longer the resident's is left alone.
	 *
	 * @param array<string, mixed> $argument `{register, caseId, collectionId, subjectRef}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
	 */
	public function link(array $argument): void {
		$register     = (string)($argument['register'] ?? '');
		$caseId       = (string)($argument['caseId'] ?? '');
		$collectionId = (string)($argument['collectionId'] ?? '');
		$subjectRef   = (string)($argument['subjectRef'] ?? '');
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null || $register === '' || $caseId === '' || $collectionId === '' || $subjectRef === '') {
			$this->logger->warning('LinkCollectionToCaseJob: nothing to link', ['case' => $caseId, 'collection' => $collectionId]);
			return;
		}

		$links = new CollectionCaseLinks(settingsService: $this->settingsService, urlGenerator: $this->urlGenerator, logger: $this->logger);
		$collection = $links->owned(objectService: $objectService, collectionId: $collectionId, subjectRef: $subjectRef);
		if ($collection === null) {
			$this->logger->warning('LinkCollectionToCaseJob: the dossier is no longer the resident\'s', ['case' => $caseId, 'collection' => $collectionId]);
			return;
		}

		$links->link(objectService: $objectService, register: $register, caseId: $caseId, collection: $collection);
	}//end link()
}//end class
