<?php

/**
 * Keeping the word on the case in step with the money in shillinq.
 *
 * 🔑 THE PROJECTION EXISTS FOR THE LIST, NOT FOR THE DECISION. A handler
 * filtering the case list on "payment outstanding" cannot ask shillinq once
 * per row, so the case carries one word. Every DECISION about the money reads
 * shillinq live: {@see \OCA\Dossiq\Lifecycle\CaseActionProvider} asks at the
 * moment it must refuse or allow, so a case that this sweep has not reached
 * yet is never handled on a stale word.
 *
 * 🔴 A PROJECTION IS ONLY AS HONEST AS ITS TIMESTAMP. Every write carries
 * `paymentStateCheckedAt`, so a reader can tell a case that was outstanding
 * five minutes ago from one that was outstanding in March and has not been
 * looked at since. A total with no timestamp cannot be told apart from a total
 * that stopped updating six months ago, and the second one is what people make
 * decisions on.
 *
 * 🔴 NOTHING HERE WRITES MONEY. The sweep reads shillinq's own report and
 * stores one of five words. No amount, no ledger line, no payment date: those
 * are shillinq's, and a copy of them here is a second set of books.
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
 *
 * @spec openspec/specs/financial-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Kernel\Contracts\CasePaymentStateInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly sweep refreshing the payment state the case list filters on.
 *
 * It runs as the background service account, because cron has no user and
 * OpenRegister refuses a write from nobody.
 *
 * @spec openspec/specs/financial-integration/spec.md
 */
class PaymentStateProjectionJob extends TimedJob {


	/**
	 * How many open cases one sweep looks at.
	 *
	 * A cap rather than paging, the same reading AutoCloseOnSilenceJob takes: a
	 * case that misses this hour's sweep is refreshed next hour, and the gate
	 * reads live in the meantime, so nothing is decided on the word this sweep
	 * did not get to.
	 *
	 * @var int
	 */
	private const SWEEP_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param CasePaymentReader $payments Reads the state from shillinq.
	 * @param SettingsServiceInterface $settingsService Bridge to OpenRegister plus config.
	 * @param IAppManager $appManager Establishes that the apps are present.
	 * @param LoggerInterface $logger The logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the run writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CasePaymentReader $payments,
		private readonly SettingsServiceInterface $settingsService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Run once as the background service account.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/financial-integration/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The job list hands every job an argument; this one takes none.
	 */
	protected function run($argument): void {
		// Without a usable account this runs nothing: that is already logged as an
		// error and told to the admins. Nothing was read, sent or
		// written; the next run tries again.
		$this->serviceAccount->runAsWhenAvailable(operation: fn () => $this->work());
	}//end run()

	/**
	 * Refresh the payment state of every open case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/financial-integration/spec.md#requirement-the-payment-state-is-on-the-case-and-read-from-shillinq-req-fee-02
	 */
	private function work(): void {
		if ($this->appManager->isInstalled(CasePaymentReader::MONEY_APP) === false) {
			// No money app, so no money facts to project. Deliberately NOT a
			// sweep that writes `notRequired` everywhere: an instance that
			// disables shillinq for a week has not waived a single fee, and a
			// projection saying it has would outlive the week.
			return;
		}

		$sweep = OpenCaseSweep::ofSettings(settingsService: $this->settingsService);
		if ($sweep === null) {
			return;
		}

		$written = 0;
		foreach ($sweep->openCases(limit: self::SWEEP_LIMIT) as $row) {
			$written += (int)$this->refreshCase(sweep: $sweep, row: $row);
		}

		$this->reportRefresh(written: $written);
	}//end work()

	/**
	 * Log how many cases were rewritten, when any were.
	 *
	 * @param int $written How many cases were rewritten.
	 *
	 * @return void
	 */
	private function reportRefresh(int $written): void {
		if ($written > 0) {
			$this->logger->info(
				'PaymentStateProjectionJob: refreshed the payment state of the open cases',
				['written' => $written],
			);
		}
	}//end reportRefresh()

	/**
	 * Refresh the payment state of one case, and say whether anything was written.
	 *
	 * @param OpenCaseSweep        $sweep Where the cases live.
	 * @param array<string, mixed> $row   The case as the sweep read it.
	 *
	 * @return bool True when the case was rewritten.
	 */
	private function refreshCase(OpenCaseSweep $sweep, array $row): bool {
		$caseId = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if ($caseId === '') {
			return false;
		}

		$projection = $this->payments->stateOf(caseId: $caseId);
		if ($this->worthWriting(row: $row, projection: $projection) === false) {
			return false;
		}

		try {
			// `paymentState` and `paymentStateCheckedAt` are readOnly: clients may not
			// write them, and OpenRegister lets only a system operation stamp them.
			$sweep->patchAsSystem(caseId: $caseId, changes: $projection);
		} catch (Throwable $e) {
			return $this->refreshFailed(caseId: $caseId, exception: $e);
		}

		return true;
	}//end refreshCase()

	/**
	 * Whether the projection is worth a write: a fresh read that moved.
	 *
	 * @param array<string, mixed> $row        The case as the sweep read it.
	 * @param array<string, mixed> $projection The state shillinq reports.
	 *
	 * @return bool
	 */
	private function worthWriting(array $row, array $projection): bool {
		// A read that failed writes NOTHING. Stamping `stale` over a case would
		// replace the last state anybody knew with the news that the sweep had a
		// bad hour, and the list would empty out every time shillinq restarted.
		// The gate reads live, so nothing depends on this row being fresh.
		if ($projection['paymentState'] === CasePaymentStateInterface::STALE) {
			return false;
		}

		// Unchanged: writing the timestamp alone would be a version of every
		// open case every hour, for a fact that did not move.
		return (string)($row['paymentState'] ?? '') !== $projection['paymentState'];
	}//end worthWriting()

	/**
	 * One case that cannot be written must not end the sweep: log it and go on.
	 *
	 * @param string    $caseId    The case.
	 * @param Throwable $exception The failure.
	 *
	 * @return bool False: nothing was written.
	 */
	private function refreshFailed(string $caseId, Throwable $exception): bool {
		$this->logger->warning(
			'PaymentStateProjectionJob: one case could not be refreshed',
			['caseId' => $caseId, 'exception' => $exception->getMessage()],
		);

		return false;
	}//end refreshFailed()
}//end class
