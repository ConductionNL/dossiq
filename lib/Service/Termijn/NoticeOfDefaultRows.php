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
class NoticeOfDefaultRows {
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
		$penalties = $this->penaltiesByNotice(objectService: $objectService, register: $register);
		$payouts = $this->payoutsByCalculation(objectService: $objectService, register: $register);
		$rows = [];
		foreach ($notices as $notice) {
			$rows[] = $this->rowOf(objectService: $objectService, register: $register, notice: $notice, penalties: $penalties, payouts: $payouts);
		}

		usort($rows, static fn (array $a, array $b): int => strcmp((string)$b['receiptDate'], (string)$a['receiptDate']));

		return ['notices' => $rows];
	}//end list()

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
	 * @param object                              $objectService The OpenRegister object service.
	 * @param string                              $register      The register id.
	 * @param array<string, mixed>                $notice        The notice.
	 * @param array<string, array<string, mixed>> $penalties     The penalty calculations by notice id.
	 * @param array<string, array<string, mixed>> $payouts       The payouts, by calculation id.
	 *
	 * @return array<string, mixed>
	 */
	private function rowOf(object $objectService, string $register, array $notice, array $penalties, array $payouts): array {
		$id = (string)($notice['id'] ?? ($notice['@self']['id'] ?? ''));
		$term = $this->read(
			objectService: $objectService,
			register: $register,
			schemaKey: 'termijn_instance_schema',
			id: (string)($notice['deadlineInstance'] ?? '')
		);
		$case = $this->read(objectService: $objectService, register: $register, schemaKey: 'case_schema', id: (string)($term['case'] ?? ''));
		$identifier = (string)($case['identifier'] ?? '');
		$title = (string)($case['title'] ?? '');

		return [
			'id'             => $id,
			'caseId'         => (string)($case['id'] ?? ($term['case'] ?? '')),
			'caseIdentifier' => $identifier,
			'caseTitle'      => $title,
			'caseLabel'      => trim($identifier.' '.$title),
			'receiptDate'    => (string)($notice['receiptDate'] ?? ''),
			'validityStatus' => (string)($notice['validityStatus'] ?? 'unknown'),
		] + $this->penaltyOf(calculation: ($penalties[$id] ?? null), payouts: $payouts);
	}//end rowOf()

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

		$calculationId = (string)($calculation['id'] ?? ($calculation['@self']['id'] ?? ''));
		$definitive = ($calculation['definitiveAmount'] ?? null);
		$since = (string)($calculation['startDate'] ?? '');
		if ($definitive !== null && $definitive !== '') {
			return [
				'penaltyState' => 'definitive',
				'penaltyEuros' => round(((int)$definitive) / 100, 2),
				'penaltySince' => $since,
				'financeState' => $this->payoutState(payout: ($payouts[$calculationId] ?? null)),
				'accruingAmountId' => $calculationId,
			];
		}

		$cents = (int)($calculation['cumulativeAmount'] ?? 0);

		return [
			'penaltyState' => 'running',
			'penaltyEuros' => round($cents / 100, 2),
			'penaltySince' => $since,
			'financeState' => 'amount-open',
			'accruingAmountId' => $calculationId,
		];
	}//end penaltyOf()

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

		$status = (string)($payout['status'] ?? '');
		if ($status === 'voorbereid' && (string)($payout['payableId'] ?? '') === '') {
			return 'prepared';
		}

		return match ($status) {
			'paid' => 'paid',
			'rejected' => 'rejected',
			'on-hold-objection' => 'on-hold',
			default => 'signal-sent',
		};
	}//end payoutState()

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
