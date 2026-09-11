<?php

/**
 * Dossiq case email match job.
 *
 * Every five minutes, links new mail in each opted-in user's Nextcloud Mail
 * account to the cases it names, through OpenRegister's email leaf. The
 * matching itself lives in {@see CaseEmailMatchService}; this job decides only
 * whether to run and for whom.
 *
 * Two switches must both be on. The instance toggle `email_case_matching_enabled`
 * defaults to off and, while off, stops the job before a single user is read.
 * The per-user preference defaults to off too, and only users who switched it
 * on are visited: they are found by their preference, not by walking every
 * account on the instance.
 *
 * Coexists with {@see InboundEmailJob}, which polls the shared functional
 * mailbox over IMAP. The two share no state; the leaf's idempotency makes a
 * message reached by both harmless (design D5).
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseEmailMatchService;
use OCA\Dossiq\Service\Email\CaseEmailMatchPreferences;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Periodic email-to-case matching over opted-in users' mail.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class CaseEmailMatchJob extends TimedJob {

	/**
	 * Run interval in seconds (REQ-ECM-008).
	 *
	 * @var int
	 */
	public const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time        Time factory.
	 * @param CaseEmailMatchService     $matcher     The matcher.
	 * @param CaseEmailMatchPreferences $preferences Who opted in, and where a failure is recorded.
	 * @param LoggerInterface           $logger      Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CaseEmailMatchService $matcher,
		private readonly CaseEmailMatchPreferences $preferences,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Run one matching pass for every opted-in user.
	 *
	 * A user whose run throws is recorded and logged, and the next user still
	 * runs: one broken mailbox never stops the others.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	protected function run(mixed $argument): void {
		if ($this->matcher->isInstanceEnabled() === false) {
			return;
		}

		$linked = 0;
		$scanned = 0;
		$failures = 0;
		foreach ($this->preferences->optedInUsers() as $userId) {
			try {
				$result = $this->matcher->runForUser(userId: (string)$userId);
				$linked += $result['linked'];
				$scanned += $result['scanned'];
			} catch (Throwable $e) {
				$failures++;
				$this->preferences->writeStatus(userId: (string)$userId, linked: 0, scanned: 0, error: 'run_failed');
				$this->logger->warning(
					'Dossiq: email case matching failed for one user, continuing with the next: ' . $e->getMessage(),
					['app' => Application::APP_ID, 'userId' => (string)$userId]
				);
			}
		}

		$this->logger->info(
			'Dossiq: email case matching complete',
			['app' => Application::APP_ID, 'linked' => $linked, 'scanned' => $scanned, 'failures' => $failures]
		);
	}//end run()
}//end class
