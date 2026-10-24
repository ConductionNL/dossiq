<?php

/**
 * Dossiq PayoutService.
 *
 * Prepares a payout of a settled amount to the party it is owed to, hands it
 * to the finance app (PayoutRequester, ADR-107), withdraws it while an
 * objection runs, and processes the signed callbacks that report its progress.
 *
 * IBAN validation is mandatory — missing/invalid IBAN raises an error
 * instead of silently skipping (REQ-TERM-007).
 *
 * Money values: integer EUR cents throughout (ADR-031).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnTimerService;
use OCP\IURLGenerator;
use RuntimeException;

/**
 * Payout preparation, finance handoff and callback processing.
 *
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */
class PayoutService {
	use SearchesObjects;

	/**
	 * Default uiterste-betaaldatum offset in days from the ingebrekestelling
	 * receipt date (AWB-default 28d).
	 */
	public const BETALING_UITERLIJK_OFFSET_DAYS = 28;

	/**
	 * The route the finance app posts each step of the payout to.
	 */
	public const CALLBACK_ROUTE = 'dossiq.payoutCallback.callback';

	/**
	 * The payout category the finance app maps to an expense account
	 * (shillinq `inboundPayableExpenseAccounts`). A contract literal an
	 * administrator has configured on the other side: frozen.
	 */
	public const CATEGORY = 'dwangsom';

	/**
	 * How a case is named to the finance app, the way shillinq's leaf identifies it; the id is added per payout.
	 *
	 * @var array<string, string>
	 */
	private const CASE_LINK = [
		'app' => Application::APP_ID,
		'type' => 'case',
		'register' => CasePaymentReader::CASE_REGISTER,
		'schema' => CasePaymentReader::CASE_SCHEMA,
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service.
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param TermijnTimerService|null $timerService The engine calendar bridge; a
	 *        statutory term end lands on a day the administered calendar works.
	 * @param PayoutRequester|null $payouts Hands the payout to the finance app.
	 * @param IURLGenerator|null $urls Builds the callback address the finance app posts to.
	 * @param OwningCaseResolver|null $owningCase Finds the case a calculation belongs to, so the payout names it.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDateNormaliser $dates,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?PayoutRequester $payouts = null,
		private readonly ?IURLGenerator $urls = null,
		private readonly ?OwningCaseResolver $owningCase = null,
	) {
	}//end __construct()

	/**
	 * Prepare a payout row for a settled calculation and raise it in the finance app.
	 *
	 * The payout is raised before the row is written: a refusal by finance
	 * writes nothing. Without a finance app the row stays prepared for the
	 * external payment provider, as before the handoff.
	 *
	 * @param string $calculationId Berekening id.
	 * @param string $accountHolderName Account holder name.
	 * @param string $iban IBAN.
	 * @param DateTimeImmutable|null $receiptDate Original ingebrekestelling receipt date (default today).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the calculation is missing, the IBAN is invalid, or finance refuses.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function preparePayout(
		string $calculationId,
		string $accountHolderName,
		string $iban,
		?DateTimeImmutable $receiptDate = null,
	): array {
		$this->assertPaymentInput(iban: $iban, accountHolderName: $accountHolderName);

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$bSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA);
		$uSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		if ($objectService === null || $register === '' || $bSchema === '' || $uSchema === '') {
			throw new RuntimeException('The accruing amount and payout schemas are not configured');
		}

		$final = $this->resolvePayableAmount(
			objectService: $objectService,
			register: $register,
			schema: $bSchema,
			calculationId: $calculationId
		);

		$receiptDate = ($receiptDate ?? $this->dates->now());
		// Awb 4:17: the day the payment is late after, so it rolls (Awt art. 1).
		$raw = $receiptDate->modify('+' . self::BETALING_UITERLIJK_OFFSET_DAYS . ' days');
		$uiterlijk = $this->dates->formatCalendarDate(
			moment: ($this->timerService?->rollTermEndFor(date: $raw) ?? $raw)
		);

		$row = [
			'penaltyPaymentCalculation' => $calculationId,
			'amount' => $final,
			'accountHolderName' => $accountHolderName,
			'iban' => strtoupper(str_replace(' ', '', $iban)),
			'reference' => $this->buildReference(calculationId: $calculationId),
			'legalBasis' => 'AWB 4:17',
			'paymentDateLatest' => $uiterlijk,
			'status' => 'voorbereid',
		];
		$row = $this->raiseRowInFinance(row: $row);
		// Finance holds the payout now; dossiq keeps no copy of it (REQ-DQM-004).
		if ((string)($row['payableId'] ?? '') !== '') {
			return $row;
		}

		return $this->persistDisbursement(
			objectService: $objectService,
			register: $register,
			schema: $uSchema,
			row: $row
		);
	}//end preparePayout()

	/**
	 * Prepare a payout from request input: accountHolderName, iban and
	 * optionally receiptDate (Y-m-d, the day the notice was received).
	 *
	 * @param string $calculationId The settled calculation.
	 * @param array<string, mixed> $input The request input.
	 *
	 * @return array<string, mixed> The saved payout row.
	 *
	 * @throws RuntimeException When the calculation is missing, the IBAN is invalid, or finance refuses.
	 * @throws \InvalidArgumentException When the receipt date is malformed.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function preparePayoutFrom(string $calculationId, array $input): array {
		$receipt = null;
		if ((string)($input['receiptDate'] ?? '') !== '') {
			$receipt = $this->dates->parse((string)$input['receiptDate'], 'receiptDate');
		}

		return $this->preparePayout(
			calculationId: $calculationId,
			accountHolderName: (string)($input['accountHolderName'] ?? ''),
			iban: (string)($input['iban'] ?? ''),
			receiptDate: $receipt
		);
	}//end preparePayoutFrom()

	/**
	 * Withdraw a payout from the finance app, for example while an objection runs.
	 *
	 * A row that never reached finance is returned unchanged. A withdrawn row
	 * loses its payableId: its reference is spent in finance, so raiseAgain()
	 * raises it under a new one.
	 *
	 * @param array<string, mixed> $row The payout row.
	 * @param string $reason Why it is withdrawn.
	 *
	 * @return array<string, mixed> The row, not yet saved.
	 *
	 * @throws RuntimeException When finance refuses, because it already approved, paid or rejected the payout.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function withdrawFromFinance(array $row, string $reason): array {
		if ((string)($row['payableId'] ?? '') === '' || $this->payouts === null) {
			return $row;
		}

		$this->payouts->cancel(reference: (string)($row['reference'] ?? ''), reason: $reason);
		$row['payableId'] = '';

		return $row;
	}//end withdrawFromFinance()


	/**
	 * Raise a prepared payout in the finance app again under a new reference,
	 * for example with the amount an objection decided.
	 *
	 * @param array<string, mixed> $row The payout row, with its new amount.
	 *
	 * @return array<string, mixed> The row, not yet saved.
	 *
	 * @throws RuntimeException When finance refuses the payout.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function raiseAgain(array $row): array {
		if ((string)($row['payableId'] ?? '') !== '' || (int)($row['amount'] ?? 0) <= 0) {
			return $row;
		}

		$previous = (string)($row['reference'] ?? '');
		$row['reference'] = $this->buildReference(calculationId: (string)($row['penaltyPaymentCalculation'] ?? ''));
		$raised = $this->raiseRowInFinance(row: $row);
		if ((string)($raised['payableId'] ?? '') === '') {
			// No finance app answered: keep the reference the provider knows.
			$row['reference'] = $previous;
			return $row;
		}

		return $raised;
	}//end raiseAgain()

	/**
	 * Hand a payout row to the finance app and note the payable it answers with.
	 * The repair that moves the rows dossiq kept calls it with their history
	 * (REQ-DQM-004); it passes only a row finance does not hold yet.
	 *
	 * @param array<string, mixed> $row The payout row.
	 * @param array<string, string> $history For a payout settled before finance kept it: historicalState, paidOn, bankReference.
	 *
	 * @return array<string, mixed> The row, with payableId when finance took it.
	 *
	 * @throws RuntimeException When finance refuses the payout.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function raiseRowInFinance(array $row, array $history = []): array {
		if ($this->payouts === null) {
			return $row;
		}

		$answer = $this->payouts->request(
			payout: [
				'amountCents' => (int)$row['amount'],
				'payeeName' => (string)$row['accountHolderName'],
				'iban' => (string)$row['iban'],
				'reference' => (string)$row['reference'],
				'description' => trim('Uitbetaling ' . (string)($row['legalBasis'] ?? '')),
				'category' => self::CATEGORY,
				'correlationId' => (string)($row['penaltyPaymentCalculation'] ?? ''),
				'dueOn' => (string)($row['paymentDateLatest'] ?? ''),
				'callbackUrl' => (string)($this->urls?->linkToRouteAbsolute(self::CALLBACK_ROUTE) ?? ''),
				'linkedObjects' => $this->caseLinkOf(calculationId: (string)($row['penaltyPaymentCalculation'] ?? '')),
			] + $history
		);
		if ($answer !== null && $answer['payableId'] !== '') {
			$row['payableId'] = $answer['payableId'];
		}

		return $row;
	}//end raiseRowInFinance()

	/**
	 * The case the calculation belongs to, as a linked object for the finance app.
	 *
	 * @param string $calculationId The calculation.
	 *
	 * @return array<int, array<string, string>> One link, or none when the case cannot be resolved.
	 */
	private function caseLinkOf(string $calculationId): array {
		$caseId = $this->owningCase?->resolveVia(
			objectId: $calculationId,
			schemaKey: MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA,
			linkField: 'deadlineInstance',
			viaSchemaKey: 'termijn_instance_schema',
			caseField: 'case'
		);
		if ($caseId === null || $caseId === '') {
			return [];
		}

		return [self::CASE_LINK + ['id' => $caseId]];
	}//end caseLinkOf()

	/**
	 * Validate the caller-supplied payment input.
	 *
	 * @param string $iban IBAN.
	 * @param string $accountHolderName Account holder name.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the IBAN or the account holder name is invalid.
	 */
	private function assertPaymentInput(string $iban, string $accountHolderName): void {
		if ($this->isValidIban(iban: $iban) === false) {
			throw new RuntimeException('Invalid IBAN provided for dwangsom uitbetaling');
		}

		if (trim($accountHolderName) === '') {
			throw new RuntimeException('rekeninghouderNaam is required');
		}
	}//end assertBetalingInput()

	/**
	 * Resolve the payable amount locked on a DwangsomBerekening.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $schema DwangsomBerekening schema identifier.
	 * @param string $calculationId Berekening id.
	 *
	 * @return int Payable amount in EUR cents.
	 *
	 * @throws RuntimeException When the berekening is missing or has nothing payable.
	 */
	private function resolvePayableAmount(
		object $objectService,
		string $register,
		string $schema,
		string $calculationId,
	): int {
		try {
			$calculation = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $calculationId);
		} catch (\Throwable $e) {
			throw new RuntimeException('Accruing amount lookup failed: ' . $e->getMessage());
		}

		if ($calculation === null) {
			throw new RuntimeException('Accruing amount not found: ' . $calculationId);
		}

		$final = (int)($calculation['definitiveAmount'] ?? $calculation['cumulativeAmount'] ?? 0);
		if ($final <= 0) {
			throw new RuntimeException('DwangsomBerekening has no payable amount');
		}

		return $final;
	}//end resolvePayableAmount()

	/**
	 * Persist a DwangsomUitbetaling row.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $schema DwangsomUitbetaling schema identifier.
	 * @param array<string, mixed> $row Row to persist.
	 *
	 * @return array<string, mixed> The saved row, or the supplied row.
	 *
	 * @throws RuntimeException When persisting fails.
	 */
	private function persistDisbursement(
		object $objectService,
		string $register,
		string $schema,
		array $row,
	): array {
		try {
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $row
			);
			return ($saved ?? $row);
		} catch (\Throwable $e) {
			throw new RuntimeException('DwangsomUitbetaling persist failed: ' . $e->getMessage());
		}
	}//end persistUitbetaling()

	/**
	 * Handle a signed callback updating the payout state.
	 *
	 * @param string $reference Payment reference.
	 * @param string $status New stored status (in-handling/paid/rejected).
	 * @param DateTimeImmutable|null $paymentDate Actual payment date.
	 * @param string $betalingsreferentie ERP/bank reference.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the referentie is unknown.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
	 */
	public function handleCallback(
		string $reference,
		string $status,
		?DateTimeImmutable $paymentDate,
		string $betalingsreferentie = '',
	): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$uSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		if ($objectService === null || $register === '' || $uSchema === '') {
			throw new RuntimeException('The accruing amount and payout schemas are not configured');
		}

		$row = $this->findDisbursementByReference(
			objectService: $objectService,
			register: $register,
			schema: $uSchema,
			reference: $reference
		);

		$row = $this->applyCallbackFields(
			row: $row,
			status: $status,
			paymentDate: $paymentDate,
			betalingsreferentie: $betalingsreferentie
		);

		return $this->persistDisbursement(
			objectService: $objectService,
			register: $register,
			schema: $uSchema,
			row: $row
		);
	}//end handleCallback()

	/**
	 * Look up the single DwangsomUitbetaling row carrying a referentie.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $schema DwangsomUitbetaling schema identifier.
	 * @param string $reference Payment reference.
	 *
	 * @return array<string, mixed> The matching row.
	 *
	 * @throws RuntimeException When the lookup fails or the referentie is unknown.
	 */
	private function findDisbursementByReference(
		object $objectService,
		string $register,
		string $schema,
		string $reference,
	): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['reference' => $reference],
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('DwangsomUitbetaling lookup failed: ' . $e->getMessage());
		}

		$row = null;
		if (count($rows) > 0) {
			$row = $rows[0];
		}

		if (is_array($row) === false) {
			throw new RuntimeException('No DwangsomUitbetaling found for referentie ' . $reference);
		}

		return $row;
	}//end findUitbetalingByReferentie()

	/**
	 * Apply the ERP callback fields onto an uitbetaling row.
	 *
	 * @param array<string, mixed> $row Uitbetaling row.
	 * @param string $status New status.
	 * @param DateTimeImmutable|null $paymentDate Actual payment date.
	 * @param string $betalingsreferentie ERP/bank reference.
	 *
	 * @return array<string, mixed> The updated row.
	 */
	private function applyCallbackFields(
		array $row,
		string $status,
		?DateTimeImmutable $paymentDate,
		string $betalingsreferentie,
	): array {
		$row['status'] = $status;
		if ($betalingsreferentie !== '') {
			$row['betalingsreferentie'] = $betalingsreferentie;
		}

		if ($paymentDate !== null) {
			$row['actualPaymentDate'] = $this->dates->formatCalendarDate($paymentDate);
		}

		return $row;
	}//end applyCallbackFields()

	/**
	 * Conservative IBAN check (length + mod-97).
	 *
	 * @param string $iban IBAN.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
	 */
	public function isValidIban(string $iban): bool {
		$iban = strtoupper(preg_replace('/\s+/', '', $iban));
		if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,32}$/', $iban) !== 1) {
			return false;
		}

		$rearranged = substr($iban, 4) . substr($iban, 0, 4);
		$expanded = '';
		foreach (str_split($rearranged) as $ch) {
			if (ctype_alpha($ch) === true) {
				$expanded .= (string)(ord($ch) - 55);
				continue;
			}

			$expanded .= $ch;
		}

		// Mod-97 over a string (PHP int can't hold this directly).
		$remainder = '';
		foreach (str_split($expanded) as $digit) {
			$remainder = (string)(((int)($remainder . $digit)) % 97);
		}

		return ((int)$remainder === 1);
	}//end isValidIban()

	/**
	 * Build a deterministic reference from a berekening id.
	 *
	 * @param string $calculationId Berekening id.
	 *
	 * @return string
	 */
	private function buildReference(string $calculationId): string {
		// Random bytes, not the clock: two payouts raised in one microsecond must not share a reference.
		return 'PROC-DWS-' . strtoupper(substr(sha1($calculationId . ':' . bin2hex(random_bytes(8))), 0, 12));
	}//end buildReferentie()
}//end class
