<?php

/**
 * Dossiq document integrity check job
 *
 * Once a day, checks the stored checksums of the next batch of documents
 * again and records each outcome with its time (DocumentIntegrityVerifier).
 * It runs as the background service account, because cron has no user and
 * OpenRegister refuses a write from nobody.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/document-integrity-check/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\Dossiq\Service\Zaakdossier\DocumentIntegrityVerifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Re-verifies a batch of stored checksums once a day.
 *
 * @spec openspec/specs/document-integrity-check/spec.md#requirement-stored-checksums-are-checked-again-and-the-check-is-recorded
 */
class DocumentIntegrityCheckJob extends TimedJob {

	/**
	 * Once per day.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Documents checked per run.
	 */
	private const BATCH = 500;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time           Time factory.
	 * @param DocumentIntegrityVerifier $verifier       Checks the stored checksums.
	 * @param BackgroundServiceAccount  $serviceAccount The account the run writes as.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DocumentIntegrityVerifier $verifier,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Run once as the background service account.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The job list hands every job an argument; this one takes none.
	 */
	protected function run($argument): void {
		try {
			$this->serviceAccount->runAs(operation: fn (): int => $this->verifier->verifyNext(batch: self::BATCH));
		} catch (ServiceAccountUnavailableException) {
			// Already logged and told to the admins; the next run tries again.
			return;
		}
	}//end run()
}//end class
