<?php

/**
 * Dossiq PayoutReader
 *
 * Reads the payouts dossiq raised back from the finance app, so the penalty
 * audit and the objection flow read the payout where its money lives instead
 * of a copy in dossiq. It asks with shillinq's PayablesRequestedEvent and
 * answers null when no finance app answers; the caller then reads the rows
 * dossiq kept before the payout moved.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use OCA\Dossiq\AppInfo\Application;
use OCA\Shillinq\Event\PayablesRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Asks the finance app for dossiq's payouts.
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
 */
class PayoutReader {
	/**
	 * Finance's payout state => the status dossiq's payout rows use.
	 *
	 * @var array<string, string>
	 */
	private const STATUSES = [
		'requested' => 'voorbereid',
		'approved' => 'in-handling',
		'paid' => 'paid',
		'rejected' => 'rejected',
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Carries the question to the finance app.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * Dossiq's penalty payouts, narrowed by calculation and by the year they were paid in.
	 *
	 * @param array<int, string> $correlationIds Only these calculations, when not empty.
	 * @param int $paidInYear Only payouts paid in this year, when above zero.
	 *
	 * @return array<int, array<string, mixed>>|null The payouts, or null when no finance app answers.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function payouts(array $correlationIds = [], int $paidInYear = 0): ?array {
		if (class_exists(PayablesRequestedEvent::class) === false) {
			return null;
		}

		$event = new PayablesRequestedEvent(
			sourceApp: Application::APP_ID,
			correlationIds: $correlationIds,
			category: PayoutService::CATEGORY,
			paidInYear: $paidInYear,
		);
		$this->dispatcher->dispatchTyped($event);
		if ($event->isHandled() === false) {
			return null;
		}

		return $event->getPayables();
	}//end payouts()

	/**
	 * The payouts as rows of the shape dossiq kept, beside the stored rows
	 * finance does not hold (a row under objection, a row not moved yet). A
	 * payout finance withdrew is no payment and is left out. Without a finance
	 * app the stored rows are the answer.
	 *
	 * @param array<int, array<string, mixed>> $storedRows The payout rows dossiq kept.
	 * @param int $paidInYear Only finance's payouts paid in this year, when above zero.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function mergedRows(array $storedRows, int $paidInYear = 0): array {
		$payouts = $this->payouts(paidInYear: $paidInYear);
		if ($payouts === null) {
			return $storedRows;
		}

		$rows = [];
		foreach ($payouts as $payout) {
			$state = (string)($payout['state'] ?? '');
			if (isset(self::STATUSES[$state]) === false) {
				continue;
			}

			$rows[] = $this->rowOf(payout: $payout, status: self::STATUSES[$state]);
		}

		foreach ($storedRows as $row) {
			if ((string)($row['payableId'] ?? '') === '') {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end mergedRows()

	/**
	 * Finance's payouts for one calculation as rows, a withdrawn one with status
	 * `withdrawn`, so an objection can raise it again with the decided amount.
	 *
	 * @param string $calculationId The calculation.
	 *
	 * @return array<int, array<string, mixed>> The rows; none without a finance app.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function rowsForCalculation(string $calculationId): array {
		$rows = [];
		foreach (($this->payouts(correlationIds: [$calculationId]) ?? []) as $payout) {
			$state = (string)($payout['state'] ?? '');
			$rows[] = $this->rowOf(payout: $payout, status: (self::STATUSES[$state] ?? 'withdrawn'));
		}

		return $rows;
	}//end rowsForCalculation()

	/**
	 * Whether finance holds a payout dossiq raised under this reference.
	 *
	 * @param string $reference The reference.
	 *
	 * @return bool True when finance answers with it.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function holds(string $reference): bool {
		foreach (($this->payouts() ?? []) as $payout) {
			if ((string)($payout['reference'] ?? '') === $reference) {
				return true;
			}
		}

		return false;
	}//end holds()

	/**
	 * One payout in the row shape dossiq kept.
	 *
	 * @param array<string, mixed> $payout The payout as finance answered it.
	 * @param string $status The row status.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function rowOf(array $payout, string $status): array {
		$payee = (array)($payout['payee'] ?? []);

		return [
			'id' => (string)($payout['payableId'] ?? ''),
			'payableId' => (string)($payout['payableId'] ?? ''),
			'penaltyPaymentCalculation' => (string)($payout['correlationId'] ?? ''),
			'reference' => (string)($payout['reference'] ?? ''),
			'amount' => (int)round(((float)($payout['amount'] ?? 0)) * 100),
			'status' => $status,
			'actualPaymentDate' => (string)($payout['paidOn'] ?? ''),
			'betalingsreferentie' => (string)($payout['bankReference'] ?? ''),
			'accountHolderName' => (string)($payee['name'] ?? ''),
			'iban' => (string)($payee['iban'] ?? ''),
			'legalBasis' => 'AWB 4:17',
		];
	}//end rowOf()
}//end class
