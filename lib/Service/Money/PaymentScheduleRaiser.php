<?php

/**
 * Dossiq payment schedule raiser.
 *
 * A decision can carry a payment schedule: the payee and a list of
 * instalments, each with a due date and an amount. Every instalment that is
 * due, and whose condition is met when it has one, is raised once with the
 * finance app as a payable. The payable id written back on the instalment is
 * what makes a second run raise nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\PaymentScheduleRaiserInterface;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raise the due instalments of every decision's payment schedule, once each.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
 */
class PaymentScheduleRaiser implements PaymentScheduleRaiserInterface {
	/**
	 * The schema whose objects carry a payment schedule.
	 */
	public const DECISION_SCHEMA = 'beschikking';

	/**
	 * Decisions read per page.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settings      Reaches OpenRegister's ObjectService.
	 * @param PayoutRequester           $payouts       Raises a payable in the finance app.
	 * @param LoggerInterface           $logger        Logger.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a findAll() row as an array.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly PayoutRequester $payouts,
		private readonly LoggerInterface $logger,
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
	) {
	}//end __construct()

	/**
	 * Raise every due instalment on every decision.
	 *
	 * @param DateTimeImmutable $today The day the run counts as.
	 *
	 * @return int How many instalments were raised.
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
	 */
	public function raiseDue(DateTimeImmutable $today): int {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		$raised = 0;
		$offset = 0;
		do {
			$rows = $this->page(objectService: $objectService, offset: $offset);
			foreach ($rows as $row) {
				$decision = $this->rowNormaliser->normalise(row: $row)['data'];
				if (empty($decision['paymentSchedule']['instalments']) === false) {
					$raised += $this->raiseForDecision(objectService: $objectService, decision: $decision, today: $today);
				}
			}

			$offset  += self::PAGE_SIZE;
			$fullPage = count($rows) === self::PAGE_SIZE;
		} while ($fullPage === true);

		return $raised;
	}//end raiseDue()

	/**
	 * Raise the due instalments of one decision and save what was raised.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $decision      The decision.
	 * @param DateTimeImmutable    $today         The day the run counts as.
	 *
	 * @return int How many instalments were raised.
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
	 */
	public function raiseForDecision(object $objectService, array $decision, DateTimeImmutable $today): int {
		[$schedule, $raised] = $this->raiseInstalments(decision: $decision, today: $today);

		if ($raised > 0) {
			$this->saveSchedule(objectService: $objectService, decision: $decision, schedule: $schedule);
		}

		return $raised;
	}//end raiseForDecision()

	/**
	 * Raise the due instalments of one decision, marking each raised one on the schedule.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param DateTimeImmutable $today The day the run counts as.
	 *
	 * @return array{0: array<string, mixed>, 1: int} The schedule and how many instalments were raised.
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
	 */
	private function raiseInstalments(array $decision, DateTimeImmutable $today): array {
		$schedule = (array)$decision['paymentSchedule'];
		$raised   = 0;
		foreach (array_keys((array)($schedule['instalments'] ?? [])) as $index) {
			$payable = $this->duePayable(decision: $decision, schedule: $schedule, index: $index, today: $today);
			if ($payable === null) {
				continue;
			}

			$schedule['instalments'][$index]['payableId'] = $payable['payableId'];
			$schedule['instalments'][$index]['raisedAt']  = $today->format('Y-m-d');
			++$raised;
		}

		return [$schedule, $raised];
	}//end raiseInstalments()

	/**
	 * Raise one instalment when it is due; null when it is not due, or finance refused or raised nothing.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param array<string, mixed> $schedule The schedule so far.
	 * @param int|string $index The instalment's position.
	 * @param DateTimeImmutable $today The day the run counts as.
	 *
	 * @return array<string, mixed>|null The payable, or null.
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
	 */
	private function duePayable(array $decision, array $schedule, int|string $index, DateTimeImmutable $today): ?array {
		$instalment = (array)$schedule['instalments'][$index];
		if ($this->isDue(instalment: $instalment, today: $today) === false) {
			return null;
		}

		$payable = null;
		try {
			$payable = $this->raise(decision: $decision, schedule: $schedule, instalment: $instalment, index: (int)$index);
		} catch (Throwable $e) {
			$this->logRefused(decision: $decision, index: $index, error: $e);
		}

		return $payable;
	}//end duePayable()

	/**
	 * Log an instalment the finance app refused.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param int|string $index The instalment's position.
	 * @param Throwable $error Why finance refused it.
	 *
	 * @return void
	 */
	private function logRefused(array $decision, int|string $index, Throwable $error): void {
		$this->logger->warning(
			'Dossiq: the finance app refused an instalment; it stays open for the next run',
			['decision' => (string)($decision['id'] ?? ''), 'instalment' => $index, 'exception' => $error->getMessage()]
		);
	}//end logRefused()

	/**
	 * Save the decision with its updated payment schedule.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $decision      The decision.
	 * @param array<string, mixed> $schedule      The schedule with the raised instalments marked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
	 */
	private function saveSchedule(object $objectService, array $decision, array $schedule): void {
		$decision['paymentSchedule'] = $schedule;
		$objectService->saveObject(
			object: $decision,
			register: AppIdentity::REGISTER_SLUG,
			schema: self::DECISION_SCHEMA,
			uuid: (string)($decision['id'] ?? $decision['uuid'] ?? '')
		);
	}//end saveSchedule()

	/**
	 * Whether an instalment is due, unraised and free of an unmet condition.
	 *
	 * @param array<string, mixed> $instalment The instalment.
	 * @param DateTimeImmutable    $today      The day the run counts as.
	 *
	 * @return bool
	 */
	private function isDue(array $instalment, DateTimeImmutable $today): bool {
		$dueOn = (string)($instalment['dueOn'] ?? '');
		if ($dueOn === '' || (string)($instalment['payableId'] ?? '') !== '' || (int)($instalment['amountCents'] ?? 0) <= 0) {
			return false;
		}

		if ((string)($instalment['condition'] ?? '') !== '' && ($instalment['conditionMet'] ?? false) !== true) {
			return false;
		}

		return substr($dueOn, 0, 10) <= $today->format('Y-m-d');
	}//end isDue()

	/**
	 * Raise one instalment with the finance app.
	 *
	 * @param array<string, mixed> $decision   The decision.
	 * @param array<string, mixed> $schedule   Its payment schedule.
	 * @param array<string, mixed> $instalment The instalment.
	 * @param int                  $index      Its place in the schedule.
	 *
	 * @return array{payableId: string, state: string}|null The payable, or null when no finance app answers.
	 *
	 * @throws \RuntimeException When the finance app refuses the payout.
	 */
	private function raise(array $decision, array $schedule, array $instalment, int $index): ?array {
		$decisionId = (string)($decision['id'] ?? $decision['uuid'] ?? '');

		return $this->payouts->request(
			[
				'amountCents' => (int)$instalment['amountCents'],
				'payeeName' => (string)($decision['addressee']['name'] ?? ''),
				'iban' => (string)($schedule['payeeIban'] ?? ''),
				'reference' => (string)($decision['reference'] ?? $decisionId) . '-' . ($index + 1),
				'description' => (string)($instalment['label'] ?? ''),
				'correlationId' => $decisionId,
				'dueOn' => substr((string)$instalment['dueOn'], 0, 10),
				'linkedObjects' => [$decisionId, (string)($decision['caseId'] ?? '')],
			]
		);
	}//end raise()

	/**
	 * One page of decisions.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param int    $offset        Where the page starts.
	 *
	 * @return array<int, mixed>
	 */
	private function page(object $objectService, int $offset): array {
		$rows = $objectService->findAll(
			[
				'filters' => ['register' => AppIdentity::REGISTER_SLUG, 'schema' => self::DECISION_SCHEMA],
				'limit' => self::PAGE_SIZE,
				'offset' => $offset,
			]
		);

		return array_values((array)$rows);
	}//end page()
}//end class
