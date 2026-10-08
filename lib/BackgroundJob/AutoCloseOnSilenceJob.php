<?php

/**
 * Nightly: warn the applicants whose cases are about to close, then close them.
 *
 * The decision is {@see SilenceCloseService}'s and the sweep is this job's, on
 * purpose. A job that also decided would be a job that could only be driven by
 * standing a whole register up, and the interesting cases here are dates: one
 * day before the warning, the day of it, the day of the close.
 *
 * The scan is narrowed SERVER-SIDE to cases that are open, not drafts and not
 * held, and then each case's own type is asked for its period. A case type
 * that declares none is the overwhelming majority and costs one read.
 *
 * OFF UNTIL A CASE TYPE OPTS IN. A case type closes its silent cases only
 * when `autoCloseOnSilence` is true; a period alone is not enough, so an
 * upgrade closes nothing on an existing instance. The sweep runs as the
 * background service account, because cron has no user and OpenRegister
 * refuses a write from nobody, and that account may abort a case only when
 * its case type opted in ({@see \OCA\Dossiq\Service\Lifecycle\LifecycleActorGate}).
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\Service\Lifecycle\SilenceCloseService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sweeps for cases whose case type says silence closes them.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */
class AutoCloseOnSilenceJob extends TimedJob {

	use SearchesObjects;

	/**
	 * How many cases one sweep looks at.
	 *
	 * A cap rather than paging, because the job runs daily and a case that
	 * misses today's sweep is closed tomorrow. An unbounded scan on a large
	 * register is the failure mode worth avoiding here.
	 *
	 * @var int
	 */
	private const SWEEP_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param SilenceCloseService $silence Decides and performs.
	 * @param SettingsService $settingsService Bridge to OpenRegister plus config.
	 * @param IAppManager $appManager Establishes that OpenRegister is present.
	 * @param LoggerInterface $logger The logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the sweep acts as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SilenceCloseService $silence,
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Sweep every open case for the silence its type declares.
	 *
	 * @param mixed $argument The job argument, unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	protected function run($argument): void {
		try {
			$this->serviceAccount->runAs(operation: fn () => $this->sweep());
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins. Nothing was
			// read, warned or closed; the next run tries again.
			return;
		}
	}//end run()

	/**
	 * Warn and close, as the background service account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/background-jobs-decisions/specs/case-status-machinery/spec.md
	 */
	private function sweep(): void {
		$cases = $this->openCases();
		if ($cases === []) {
			return;
		}

		$today = new DateTimeImmutable('today');
		$warned = 0;
		$closed = 0;

		foreach ($cases as $case) {
			$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
			if ($caseId === '') {
				continue;
			}

			try {
				$decision = $this->silence->decide(case: $case, today: $today);
				if ($decision['action'] === 'warn') {
					$this->silence->warn(caseId: $caseId, case: $case, closesOn: $decision['closesOn']);
					$warned++;
				}

				if ($decision['action'] === 'close') {
					$this->silence->close(caseId: $caseId, decision: $decision);
					$closed++;
				}
			} catch (Throwable $e) {
				// One case that cannot be evaluated must not end the sweep:
				// the next four hundred are unrelated to it.
				$this->logger->warning(
					'AutoCloseOnSilenceJob: one case could not be evaluated',
					['caseId' => $caseId, 'exception' => $e->getMessage()],
				);
			}//end try
		}

		if (($warned + $closed) > 0) {
			$this->logger->info(
				'AutoCloseOnSilenceJob: swept the declared silence periods',
				['warned' => $warned, 'closed' => $closed],
			);
		}
	}//end sweep()

	/**
	 * The open, non-draft cases this sweep considers.
	 *
	 * @return array<int, array<string, mixed>> The rows, empty when unreadable.
	 *
	 * @spec exclude one read behind run(), which carries the requirement
	 */
	private function openCases(): array {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				'isFinalStatus' => 0,
				'isDraft' => 0,
				'_limit' => self::SWEEP_LIMIT,
			],
		);
	}//end openCases()
}//end class
