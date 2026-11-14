<?php

/**
 * Dossiq NoticeOfDefaultRows.
 *
 * The notices of default, one row per notice, named by the case it was given
 * on: the case number and title, the day it came in, how it was assessed,
 * the penalty it started and where that money stands. The deadline
 * dashboard lists them (board DqTermijnen, "Ingebrekestellingen en
 * dwangsom").
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Kernel\Contracts\NoticeOfDefaultRowsInterface;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Throwable;

/**
 * Lists the notices of default, each named by its case, with its penalty.
 *
 * A notice points at its term, the term at the case. A penalty calculation
 * points at the notice it started from. A calculation with a definitive
 * amount is fixed and ready for payout until a payout is prepared; the
 * payout's own status (stored, or held by the finance app) then says where
 * the money stands. Any other calculation is still running money, its amount
 * not fixed yet.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
 */
class NoticeOfDefaultRows implements NoticeOfDefaultRowsInterface {
	use SearchesObjects;

	/**
	 * Objects read once per call, by schema and id.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $found = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService The app settings, for the object service and the schema ids.
	 * @param PayoutReader|null $payoutReader    The payouts the finance app holds; null reads only stored payouts.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ?PayoutReader $payoutReader = null,
	) {
	}//end __construct()

	/**
	 * The notices of default on one case, newest receipt first.
	 *
	 * The same rows as the deadlines report, kept to the case the notice's
	 * term belongs to, so the case page and the report never disagree about a
	 * notice's assessment, penalty or payout.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{notices: list<array<string, mixed>>}
	 *
	 * @throws Throwable When the notices or the penalty calculations cannot be read.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md
	 */
	public function forCase(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return ['notices' => []];
		}

		$rows = array_filter(
			$this->list()['notices'],
			static fn (array $row): bool => ($row['caseId'] ?? '') === $caseId
		);

		return ['notices' => array_values($rows)];
	}//end forCase()

	/**
	 * The notices of default, newest receipt first.
	 *
	 * @return array{notices: list<array<string, mixed>>}
	 *
	 * @throws Throwable When the notices or the penalty calculations cannot be read; a list
	 *                   without them would read "no notices" or "no penalty".
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function list(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$noticeSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::NOTICE_OF_DEFAULT_SCHEMA);
		if ($objectService === null || $register === '' || $noticeSchema === '') {
			return ['notices' => []];
		}

		$notices = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $noticeSchema, filters: []);

		$this->found = [];
		$run = new NoticeListRun(objectService: $objectService, register: $register);
		$run->penalties = $this->penaltiesByNotice(objectService: $objectService, register: $register);
		$run->payouts = $this->payoutsByCalculation(objectService: $objectService, register: $register);

		return ['notices' => $this->newestFirst(run: $run, notices: $notices)];
	}//end list()

	/**
	 * Every notice as a row, newest receipt first.
	 *
	 * @param NoticeListRun                    $run     The listing.
	 * @param array<int, array<string, mixed>> $notices The notices.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function newestFirst(NoticeListRun $run, array $notices): array {
		$rows = [];
		foreach ($notices as $notice) {
			$rows[] = $this->rowOf(run: $run, notice: $notice);
		}

		usort($rows, static fn (array $a, array $b): int => strcmp((string)$b['receiptDate'], (string)$a['receiptDate']));

		return $rows;
	}//end newestFirst()

	/**
	 * The penalty calculations by the notice each started from.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register id.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws Throwable When the calculations cannot be read.
	 */
	private function penaltiesByNotice(object $objectService, string $register): array {
		$schema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA);
		if ($schema === '') {
			return [];
		}

		$calculations = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);

		$byNotice = [];
		foreach ($calculations as $calculation) {
			$notice = (string)($calculation['noticeOfDefault'] ?? '');
			if ($notice !== '') {
				$byNotice[$notice] = $calculation;
			}
		}

		return $byNotice;
	}//end penaltiesByNotice()

	/**
	 * One notice as a row, named by its case.
	 *
	 * @param NoticeListRun        $run    The listing.
	 * @param array<string, mixed> $notice The notice.
	 *
	 * @return array<string, mixed>
	 */
	private function rowOf(NoticeListRun $run, array $notice): array {
		$id = (string)($notice['id'] ?? ($notice['@self']['id'] ?? ''));
		$term = $this->read(
			objectService: $run->objectService,
			register: $run->register,
			schemaKey: 'termijn_instance_schema',
			id: (string)($notice['deadlineInstance'] ?? '')
		);
		$case = $this->read(objectService: $run->objectService, register: $run->register, schemaKey: 'case_schema', id: (string)($term['case'] ?? ''));

		return ['id' => $id]
			+ $this->caseColumns(case: $case, term: $term)
			+ [
				'receiptDate'    => (string)($notice['receiptDate'] ?? ''),
				'validityStatus' => (string)($notice['validityStatus'] ?? 'unknown'),
			]
			+ $this->penaltyOf(calculation: ($run->penalties[$id] ?? null), payouts: $run->payouts);
	}//end rowOf()

	/**
	 * The columns that name a notice's case.
	 *
	 * @param array<string, mixed> $case The case, or [] when it cannot be read.
	 * @param array<string, mixed> $term The notice's term, or [].
	 *
	 * @return array{caseId: string, caseIdentifier: string, caseTitle: string, caseLabel: string}
	 */
	private function caseColumns(array $case, array $term): array {
		$identifier = (string)($case['identifier'] ?? '');
		$title = (string)($case['title'] ?? '');

		return [
			'caseId'         => (string)($case['id'] ?? ($term['case'] ?? '')),
			'caseIdentifier' => $identifier,
			'caseTitle'      => $title,
			'caseLabel'      => trim($identifier.' '.$title),
		];
	}//end caseColumns()

	/**
	 * The payouts, by the calculation they pay: the stored ones, merged with
	 * those the finance app holds when it answers.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register id.
	 *
	 * @return array<string, array<string, mixed>> The payout per calculation id.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
	 */
	private function payoutsByCalculation(object $objectService, string $register): array {
		$schema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		$stored = [];
		if ($schema !== '') {
			$stored = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
		}

		if ($this->payoutReader !== null) {
			$stored = $this->payoutReader->mergedRows(storedRows: $stored);
		}

		$byCalculation = [];
		foreach ($stored as $payout) {
			$calculation = (string)($payout['penaltyPaymentCalculation'] ?? '');
			if ($calculation !== '') {
				$byCalculation[$calculation] = $payout;
			}
		}

		return $byCalculation;
	}//end payoutsByCalculation()

	/**
	 * A notice's penalty: fixed, running or none, its amount in euros, the day
	 * it started and where the money stands.
	 *
	 * @param array<string, mixed>|null           $calculation The calculation the notice started, if any.
	 * @param array<string, array<string, mixed>> $payouts     The payouts, by calculation id.
	 *
	 * @return array{penaltyState: string, penaltyEuros: float, penaltySince: string, financeState: string|null, accruingAmountId: string}
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
	 */
	private function penaltyOf(?array $calculation, array $payouts): array {
		if ($calculation === null) {
			return ['penaltyState' => 'none', 'penaltyEuros' => 0.0, 'penaltySince' => '', 'financeState' => null, 'accruingAmountId' => ''];
		}

		$definitive = ($calculation['definitiveAmount'] ?? null);
		if ($definitive !== null && $definitive !== '') {
			return $this->definitivePenalty(calculation: $calculation, payouts: $payouts);
		}

		$cents = (int)($calculation['cumulativeAmount'] ?? 0);

		return [
			'penaltyState' => 'running',
			'penaltyEuros' => round($cents / 100, 2),
			'penaltySince' => (string)($calculation['startDate'] ?? ''),
			'financeState' => 'amount-open',
			'accruingAmountId' => $this->calculationId(calculation: $calculation),
		];
	}//end penaltyOf()

	/**
	 * A fixed penalty: its definitive amount in euros and where its payout stands.
	 *
	 * @param array<string, mixed>                $calculation The calculation, with a definitive amount.
	 * @param array<string, array<string, mixed>> $payouts     The payouts, by calculation id.
	 *
	 * @return array{penaltyState: string, penaltyEuros: float, penaltySince: string, financeState: string, accruingAmountId: string}
	 */
	private function definitivePenalty(array $calculation, array $payouts): array {
		$calculationId = $this->calculationId(calculation: $calculation);

		return [
			'penaltyState' => 'definitive',
			'penaltyEuros' => round(((int)$calculation['definitiveAmount']) / 100, 2),
			'penaltySince' => (string)($calculation['startDate'] ?? ''),
			'financeState' => $this->payoutState(payout: ($payouts[$calculationId] ?? null)),
			'accruingAmountId' => $calculationId,
		];
	}//end definitivePenalty()

	/**
	 * A calculation's id, from its own field or its `@self`.
	 *
	 * @param array<string, mixed> $calculation The calculation.
	 *
	 * @return string The id, or ''.
	 */
	private function calculationId(array $calculation): string {
		return (string)($calculation['id'] ?? ($calculation['@self']['id'] ?? ''));
	}//end calculationId()

	/**
	 * Where the payout of a fixed amount stands.
	 *
	 * A prepared payout the finance app took (it has a payable id) or approved
	 * has gone out as a payment signal; one only stored here is prepared.
	 *
	 * @param array<string, mixed>|null $payout The payout, if one was prepared.
	 *
	 * @return string payout-due, prepared, signal-sent, paid, rejected or on-hold.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
	 */
	private function payoutState(?array $payout): string {
		if ($payout === null) {
			return 'payout-due';
		}

		if ($this->isOnlyStoredHere(payout: $payout) === true) {
			return 'prepared';
		}

		$status = (string)($payout['status'] ?? '');

		return match ($status) {
			'paid' => 'paid',
			'rejected' => 'rejected',
			'on-hold-objection' => 'on-hold',
			default => 'signal-sent',
		};
	}//end payoutState()

	/**
	 * Whether a payout is prepared here and not yet taken by the finance app.
	 *
	 * @param array<string, mixed> $payout The payout.
	 *
	 * @return bool True when it is `voorbereid` and carries no payable id.
	 */
	private function isOnlyStoredHere(array $payout): bool {
		return ((string)($payout['status'] ?? '') === 'voorbereid' && (string)($payout['payableId'] ?? '') === '');
	}//end isOnlyStoredHere()

	/**
	 * Read one object by the schema a setting names, once per call.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register id.
	 * @param string $schemaKey     The setting that names the schema.
	 * @param string $id            The object id.
	 *
	 * @return array<string, mixed>
	 */
	private function read(object $objectService, string $register, string $schemaKey, string $id): array {
		$schema = (string)$this->settingsService->getConfigValue($schemaKey);
		if ($id === '' || $schema === '') {
			return [];
		}

		$key = $schemaKey.':'.$id;
		if (array_key_exists($key, $this->found) === false) {
			try {
				$this->found[$key] = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
			} catch (Throwable $e) {
				$this->found[$key] = null;
			}
		}

		return ($this->found[$key] ?? []);
	}//end read()
}//end class
