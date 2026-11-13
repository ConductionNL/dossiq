<?php

/**
 * Dossiq repair step base: fill a value OpenRegister materialises on a case
 * from a row the case refers to, on the cases saved before the value existed.
 *
 * OpenRegister computes a materialised `@ref` calculation when a case is
 * SAVED, so a case last saved before the field existed has none until
 * somebody edits it. OpenRegister's own `occ openregister:rematerialise-calculations`
 * writes as "Anonymous", which the case schema refuses (5 October 2026), so
 * these steps do the same job in system context.
 *
 * What a step writes, and what it leaves alone:
 * - only a case whose referenced row carries a value, and whose stored value
 *   differs from it, which is what makes a second run a no-op;
 * - nothing it supplies itself: the case schema declares a materialised field
 *   readOnly, and OpenRegister refuses a write that changes one. The step
 *   re-saves the case unchanged and OpenRegister's save-time calculation
 *   fills the field, as its rematerialise command does. It changes no status,
 *   so it is no transition, and the notifications the case schema declares
 *   have nothing to fire on.
 *
 * It never throws. One row that refuses is logged and counted, and the
 * upgrade goes on. A subclass names the field, the reference and the
 * property it is read from.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Backfill one materialised reference value on the cases that exist.
 *
 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
 */
abstract class BackfillReferencedCaseValue implements IRepairStep {

	use SearchesObjects;

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private const PAGE = 200;

	/**
	 * A ceiling on the paging, so a store that never answers a short page
	 * cannot keep an upgrade running for ever.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Names the register and the schemas, and hands out the object service.
	 * @param LoggerInterface $logger          Records a row that refused.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The case field the step fills, for example `statusRole`.
	 *
	 * @return string The field.
	 */
	abstract protected function field(): string;

	/**
	 * The case field that refers to the row the value is read from, for example `status`.
	 *
	 * @return string The reference field.
	 */
	abstract protected function referenceField(): string;

	/**
	 * The app config key naming the schema of the referred row, for example `status_type_schema`.
	 *
	 * @return string The config key.
	 */
	abstract protected function targetSchemaKey(): string;

	/**
	 * The property of the referred row the value is, for example `role`.
	 *
	 * @return string The property.
	 */
	abstract protected function targetProperty(): string;

	/**
	 * What a case that is skipped lacks, closing the report line ("a role").
	 *
	 * @return string The words.
	 */
	abstract protected function missingWhat(): string;

	/**
	 * The schemas the step needs, named in the line it writes when one is not configured.
	 *
	 * @return string The words ("the case or status type schema").
	 */
	abstract protected function schemasWhat(): string;

	/**
	 * Run the backfill and say what it did.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
	 */
	public function run(IOutput $output): void {
		$backfill = $this->configuredBackfill(output: $output);
		if ($backfill === null) {
			return;
		}

		try {
			$tally = (array)$this->runAsSystemIfAvailable(
				objectService: $backfill->objectService,
				operation: fn (): array => $this->backfill(backfill: $backfill)
			);
		} catch (Throwable $e) {
			$this->reportUnreadableCases(output: $output, error: $e);
			return;
		}

		$output->info($this->tallyLine(tally: $tally));
	}//end run()

	/**
	 * Where this step reads and writes, or null when it cannot run; the reason is said in the output.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return CaseValueBackfill|null The backfill.
	 */
	private function configuredBackfill(IOutput $output): ?CaseValueBackfill {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('OpenRegister unavailable, skipping the ' . $this->field() . ' backfill.');
			return null;
		}

		return $this->backfillIn(objectService: $objectService, output: $output);
	}//end configuredBackfill()

	/**
	 * The backfill over the configured register and schemas, or null when one is not configured.
	 *
	 * @param object  $objectService The OpenRegister object service.
	 * @param IOutput $output        The upgrade output.
	 *
	 * @return CaseValueBackfill|null The backfill.
	 */
	private function backfillIn(object $objectService, IOutput $output): ?CaseValueBackfill {
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$targetSchema = (string)$this->settingsService->getConfigValue($this->targetSchemaKey());
		if ($register === '' || $caseSchema === '' || $targetSchema === '') {
			$output->info($this->field() . ' backfill: ' . $this->schemasWhat() . ' is not configured, skipped.');
			return null;
		}

		return new CaseValueBackfill(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			targetSchema: $targetSchema
		);
	}//end backfillIn()

	/**
	 * Say that the cases could not be read, in the log and in the output.
	 *
	 * @param IOutput   $output The upgrade output.
	 * @param Throwable $error  Why the read failed.
	 *
	 * @return void
	 */
	private function reportUnreadableCases(IOutput $output, Throwable $error): void {
		$field = $this->field();
		$this->logger->warning(
			'Dossiq: the ' . $field . ' backfill could not read the cases',
			['app' => AppIdentity::APP_ID, 'error' => $error->getMessage()]
		);
		$output->info($field . ' backfill: the cases could not be read, skipped.');
	}//end reportUnreadableCases()

	/**
	 * The line that says what the step did.
	 *
	 * @param array<string, mixed> $tally What happened.
	 *
	 * @return string The line.
	 */
	private function tallyLine(array $tally): string {
		return $this->field() . ' backfill: ' . (int)($tally['filled'] ?? 0) . ' filled, '
			. (int)($tally['unconfirmed'] ?? 0) . ' written but not confirmed, '
			. (int)($tally['failed'] ?? 0) . ' failed, '
			. (int)($tally['skipped'] ?? 0) . ' already right or without ' . $this->missingWhat() . '.';
	}//end tallyLine()

	/**
	 * The value a case should carry, or an empty string when it needs no write.
	 *
	 * Empty when the case refers to nothing, when the referred row carries no
	 * value, and when the case already carries that value.
	 *
	 * @param array<string, mixed>  $case   The stored case.
	 * @param array<string, string> $values Value by referred row uuid (lower-cased).
	 *
	 * @return string The value to write, or ''.
	 *
	 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
	 */
	public function valueToWrite(array $case, array $values): string {
		$value = $this->referredValue(reference: $this->referenceOf(case: $case), values: $values);
		if ($value === '' || ($case[$this->field()] ?? null) === $value) {
			return '';
		}

		return $value;
	}//end valueToWrite()

	/**
	 * The uuid the case refers to, whether the reference is a uuid or the referred row itself.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return mixed The reference.
	 */
	private function referenceOf(array $case): mixed {
		$reference = ($case[$this->referenceField()] ?? null);
		if (is_array($reference) === true) {
			// An extended read hands the referred row over whole.
			$reference = ($reference['id'] ?? ($reference['uuid'] ?? null));
		}

		return $reference;
	}//end referenceOf()

	/**
	 * The value the referred row carries, or '' when the case refers to nothing.
	 *
	 * @param mixed                 $reference The reference.
	 * @param array<string, string> $values    Value by referred row uuid (lower-cased).
	 *
	 * @return string The value, or ''.
	 */
	private function referredValue(mixed $reference, array $values): string {
		if (is_string($reference) === false || $reference === '') {
			return '';
		}

		return ($values[strtolower($reference)] ?? '');
	}//end referredValue()

	/**
	 * Walk every case and write the value where it is missing or stale.
	 *
	 * @param CaseValueBackfill $backfill Where the step reads and writes.
	 *
	 * @return array{filled: int, unconfirmed: int, failed: int, skipped: int} What happened.
	 */
	private function backfill(CaseValueBackfill $backfill): array {
		$tally = ['filled' => 0, 'unconfirmed' => 0, 'failed' => 0, 'skipped' => 0];
		$values = $this->valuesByRow(backfill: $backfill);
		if ($values === []) {
			return $tally;
		}

		// The cases are read first and written after. Writing while paging by
		// offset can move a row between pages, and then one case is seen twice
		// and another not at all.
		$plan = $this->casesToWrite(backfill: $backfill, values: $values);
		$tally['skipped'] = $plan['skipped'];
		foreach ($plan['todo'] as $pending) {
			$tally[$this->writeValue(backfill: $backfill, pending: $pending)]++;
		}

		return $tally;
	}//end backfill()

	/**
	 * Every case that needs a write, by uuid, and how many need none.
	 *
	 * @param CaseValueBackfill     $backfill Where the step reads and writes.
	 * @param array<string, string> $values   Value by referred row uuid (lower-cased).
	 *
	 * @return array{todo: array<string, PendingCaseValue>, skipped: int} The plan.
	 */
	private function casesToWrite(CaseValueBackfill $backfill, array $values): array {
		$plan = ['todo' => [], 'skipped' => 0];
		foreach ($this->rowsOf(backfill: $backfill, schema: $backfill->caseSchema) as $row) {
			$pending = $this->pendingFor(case: $row, values: $values);
			if ($pending === null) {
				$plan['skipped']++;
				continue;
			}

			$plan['todo'][$pending->uuid] = $pending;
		}

		return $plan;
	}//end casesToWrite()

	/**
	 * The write one case needs, or null when it needs none or carries no uuid.
	 *
	 * @param array<string, mixed>  $case   The stored case.
	 * @param array<string, string> $values Value by referred row uuid (lower-cased).
	 *
	 * @return PendingCaseValue|null The write.
	 */
	private function pendingFor(array $case, array $values): ?PendingCaseValue {
		$value = $this->valueToWrite(case: $case, values: $values);
		$uuid = $this->uuidOf(row: $case);
		if ($value === '' || $uuid === '') {
			return null;
		}

		return new PendingCaseValue(uuid: $uuid, value: $value);
	}//end pendingFor()

	/**
	 * Re-save one case so OpenRegister materialises its value, and report how it went.
	 *
	 * The patch is empty: supplying a readOnly value is refused.
	 * `filled` when the stored case answers the value back. `unconfirmed` when
	 * the write went through and the answer does not carry it, which is a
	 * store that dropped the field and worth a line in the output.
	 *
	 * @param CaseValueBackfill $backfill Where the step writes.
	 * @param PendingCaseValue  $pending  The case and the value the save should materialise.
	 *
	 * @return string `filled`, `unconfirmed` or `failed`.
	 */
	private function writeValue(CaseValueBackfill $backfill, PendingCaseValue $pending): string {
		try {
			$stored = $this->resave(backfill: $backfill, uuid: $pending->uuid);
		} catch (Throwable $e) {
			$this->reportFailedWrite(uuid: $pending->uuid, error: $e);
			return 'failed';
		}

		if (($stored[$this->field()] ?? null) === $pending->value) {
			return 'filled';
		}

		return 'unconfirmed';
	}//end writeValue()

	/**
	 * Log a case whose re-save refused; the step goes on with the next.
	 *
	 * @param string    $uuid  The case.
	 * @param Throwable $error Why the save refused.
	 *
	 * @return void
	 */
	private function reportFailedWrite(string $uuid, Throwable $error): void {
		$this->logger->error(
			'Dossiq: the ' . $this->field() . ' backfill failed for one case',
			['app' => AppIdentity::APP_ID, 'uuid' => $uuid, 'exception' => $error->getMessage()]
		);
	}//end reportFailedWrite()

	/**
	 * Save one case unchanged.
	 *
	 * @param CaseValueBackfill $backfill Where the step writes.
	 * @param string            $uuid     The case.
	 *
	 * @return array<string, mixed>|null The stored case.
	 */
	private function resave(CaseValueBackfill $backfill, string $uuid): ?array {
		return $this->patchObjectAsArray(
			objectService: $backfill->objectService,
			register: $backfill->register,
			schema: $backfill->caseSchema,
			id: $uuid,
			changes: []
		);
	}//end resave()

	/**
	 * The value every referred row carries, by row uuid.
	 *
	 * @param CaseValueBackfill $backfill Where the step reads.
	 *
	 * @return array<string, string> Value by lower-cased uuid; a row without a value is left out.
	 */
	private function valuesByRow(CaseValueBackfill $backfill): array {
		$property = $this->targetProperty();
		$values = [];
		foreach ($this->rowsOf(backfill: $backfill, schema: $backfill->targetSchema) as $row) {
			$uuid = $this->uuidOf(row: $row);
			$value = ($row[$property] ?? null);
			if ($uuid !== '' && is_string($value) === true && $value !== '') {
				$values[strtolower($uuid)] = $value;
			}
		}

		return $values;
	}//end valuesByRow()

	/**
	 * Every row of a schema, read a page at a time up to the paging ceiling.
	 *
	 * @param CaseValueBackfill $backfill Where the step reads.
	 * @param string            $schema   The schema.
	 *
	 * @return iterable<int, array<string, mixed>> The rows, page after page.
	 */
	private function rowsOf(CaseValueBackfill $backfill, string $schema): iterable {
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $backfill->objectService,
				register: $backfill->register,
				schema: $schema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);
			yield from $rows;
			if (count($rows) < self::PAGE) {
				return;
			}
		}
	}//end rowsOf()

	/**
	 * A row's uuid, wherever the read put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function uuidOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
	}//end uuidOf()
}//end class
