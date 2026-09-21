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
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\CasePaymentState;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly sweep refreshing the payment state the case list filters on.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */
class PaymentStateProjectionJob extends TimedJob {

	use SearchesObjects;

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
	 * @param SettingsService $settingsService Bridge to OpenRegister plus config.
	 * @param IAppManager $appManager Establishes that the apps are present.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CasePaymentReader $payments,
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Refresh the payment state of every open case.
	 *
	 * @param mixed $argument The job argument, unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md#requirement-the-payment-state-is-on-the-case-and-read-from-shillinq-req-fee-02
	 */
	protected function run($argument): void {
		if ($this->appManager->isInstalled(CasePaymentReader::MONEY_APP) === false) {
			// No money app, so no money facts to project. Deliberately NOT a
			// sweep that writes `notRequired` everywhere: an instance that
			// disables shillinq for a week has not waived a single fee, and a
			// projection saying it has would outlive the week.
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			return;
		}

		$cases = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				'isFinalStatus' => 0,
				'isDraft' => 0,
				'_limit' => self::SWEEP_LIMIT,
			],
		);

		$written = 0;
		foreach ($cases as $case) {
			$refreshed = $this->refreshCase(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				case: $case,
			);
			$written += (int)$refreshed;
		}

		if ($written > 0) {
			$this->logger->info(
				'PaymentStateProjectionJob: refreshed the payment state of the open cases',
				['written' => $written],
			);
		}
	}//end run()

	/**
	 * Refresh the payment state of one case, and say whether anything was written.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register the cases live in.
	 * @param string               $schema        The case schema.
	 * @param array<string, mixed> $case          The case as the sweep read it.
	 *
	 * @return bool True when the case was rewritten.
	 */
	private function refreshCase(object $objectService, string $register, string $schema, array $case): bool {
		$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		if ($caseId === '') {
			return false;
		}

		$projection = $this->payments->stateOf(caseId: $caseId);

		// A read that failed writes NOTHING. Stamping `stale` over a case would
		// replace the last state anybody knew with the news that the sweep had a
		// bad hour, and the list would empty out every time shillinq restarted.
		// The gate reads live, so nothing depends on this row being fresh.
		if ($projection['paymentState'] === CasePaymentState::STALE) {
			return false;
		}

		if ((string)($case['paymentState'] ?? '') === $projection['paymentState']) {
			// Unchanged. Writing the timestamp alone would be a version of every
			// open case every hour, for a fact that did not move.
			return false;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: $projection,
			);
		} catch (Throwable $e) {
			// One case that cannot be written must not end the sweep.
			$this->logger->warning(
				'PaymentStateProjectionJob: one case could not be refreshed',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return false;
		}//end try

		return true;
	}//end refreshCase()
}//end class
