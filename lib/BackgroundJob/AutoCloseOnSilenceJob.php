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
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SilenceCloseServiceInterface;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
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
 * @spec openspec/specs/case-status-machinery/spec.md
 */
class AutoCloseOnSilenceJob extends TimedJob {


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
	 * @param SilenceCloseServiceInterface $silence Decides and performs.
	 * @param SettingsServiceInterface $settingsService Bridge to OpenRegister plus config.
	 * @param IAppManager $appManager Establishes that OpenRegister is present.
	 * @param LoggerInterface $logger The logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the sweep acts as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SilenceCloseServiceInterface $silence,
		private readonly SettingsServiceInterface $settingsService,
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
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	protected function run($argument): void {
		// Without a usable account this runs nothing: that is already logged as an
		// error and told to the admins. Nothing was read, warned or
		// closed; the next run tries again.
		$this->serviceAccount->runAsWhenAvailable(operation: fn () => $this->sweep());
	}//end run()

	/**
	 * Warn and close, as the background service account.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	private function sweep(): void {
		$cases = $this->openCases();
		if ($cases === []) {
			return;
		}

		$today = new DateTimeImmutable('today');
		$tally = ['warn' => 0, 'close' => 0];
		foreach ($cases as $case) {
			$done = $this->evaluate(row: $case, today: $today);
			if (isset($tally[$done]) === true) {
				$tally[$done]++;
			}
		}

		$this->reportSweep(tally: $tally);
	}//end sweep()

	/**
	 * Decide one case and act on it; one case that cannot be evaluated never ends the sweep.
	 *
	 * @param array<string, mixed> $row   The case as the sweep read it.
	 * @param DateTimeImmutable    $today The day of the sweep.
	 *
	 * @return string `warn` or `close` when that was done, else ''.
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	private function evaluate(array $row, DateTimeImmutable $today): string {
		$caseId = $this->caseIdOf(row: $row);
		if ($caseId === '') {
			return '';
		}

		try {
			return $this->act(row: $row, decision: $this->silence->decide(case: $row, today: $today));
		} catch (Throwable $e) {
			// One case that cannot be evaluated must not end the sweep:
			// the next four hundred are unrelated to it.
			$this->logger->warning(
				'AutoCloseOnSilenceJob: one case could not be evaluated',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);
			return '';
		}//end try
	}//end evaluate()

	/**
	 * Warn or close as the decision says.
	 *
	 * @param array<string, mixed> $row      The case.
	 * @param array<string, mixed> $decision The silence decision.
	 *
	 * @return string `warn` or `close` when that was done, else ''.
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	private function act(array $row, array $decision): string {
		if ($decision['action'] === 'warn') {
			$this->silence->warn(caseId: $this->caseIdOf(row: $row), case: $row, closesOn: $decision['closesOn']);
			return 'warn';
		}

		if ($decision['action'] === 'close') {
			$this->silence->close(caseId: $this->caseIdOf(row: $row), decision: $decision);
			return 'close';
		}

		return '';
	}//end act()

	/**
	 * The case's id, from the row or its `@self`.
	 *
	 * @param array<string, mixed> $row The case.
	 *
	 * @return string The id, or ''.
	 */
	private function caseIdOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end caseIdOf()

	/**
	 * Log what the sweep did, when it did anything.
	 *
	 * @param array{warn: int, close: int} $tally How many were warned and closed.
	 *
	 * @return void
	 */
	private function reportSweep(array $tally): void {
		if (($tally['warn'] + $tally['close']) > 0) {
			$this->logger->info(
				'AutoCloseOnSilenceJob: swept the declared silence periods',
				['warned' => $tally['warn'], 'closed' => $tally['close']],
			);
		}
	}//end reportSweep()

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

		$sweep = OpenCaseSweep::ofSettings(settingsService: $this->settingsService);
		if ($sweep === null) {
			return [];
		}

		return $sweep->openCases(limit: self::SWEEP_LIMIT);
	}//end openCases()
}//end class
