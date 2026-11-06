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

use OCA\Dossiq\AppInfo\Application;
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
		$field = $this->field();
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('OpenRegister unavailable, skipping the ' . $field . ' backfill.');
			return;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$targetSchema = (string)$this->settingsService->getConfigValue($this->targetSchemaKey());
		if ($register === '' || $caseSchema === '' || $targetSchema === '') {
			$output->info($field . ' backfill: ' . $this->schemasWhat() . ' is not configured, skipped.');
			return;
		}

		try {
			$tally = (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->backfill(
					objectService: $objectService,
					register: $register,
					caseSchema: $caseSchema,
					targetSchema: $targetSchema
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the ' . $field . ' backfill could not read the cases',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
			$output->info($field . ' backfill: the cases could not be read, skipped.');
			return;
		}

		$output->info(
			$field . ' backfill: ' . (int)($tally['filled'] ?? 0) . ' filled, '
			. (int)($tally['unconfirmed'] ?? 0) . ' written but not confirmed, '
			. (int)($tally['failed'] ?? 0) . ' failed, '
			. (int)($tally['skipped'] ?? 0) . ' already right or without ' . $this->missingWhat() . '.'
		);
	}//end run()

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
		$reference = ($case[$this->referenceField()] ?? null);
		if (is_array($reference) === true) {
			// An extended read hands the referred row over whole.
			$reference = ($reference['id'] ?? ($reference['uuid'] ?? null));
		}

		if (is_string($reference) === false || $reference === '') {
			return '';
		}

		$value = ($values[strtolower($reference)] ?? '');
		if ($value === '' || ($case[$this->field()] ?? null) === $value) {
			return '';
		}

		return $value;
	}//end valueToWrite()

	/**
	 * Walk every case and write the value where it is missing or stale.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The dossiq register.
	 * @param string $caseSchema    The case schema.
	 * @param string $targetSchema  The schema of the referred rows.
	 *
	 * @return array{filled: int, unconfirmed: int, failed: int, skipped: int} What happened.
	 */
	private function backfill(object $objectService, string $register, string $caseSchema, string $targetSchema): array {
		$tally = ['filled' => 0, 'unconfirmed' => 0, 'failed' => 0, 'skipped' => 0];
		$values = $this->valuesByRow(objectService: $objectService, register: $register, schema: $targetSchema);
		if ($values === []) {
			return $tally;
		}

		// The cases are read first and written after. Writing while paging by
		// offset can move a row between pages, and then one case is seen twice
		// and another not at all.
		$todo = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);

			foreach ($rows as $row) {
				$value = $this->valueToWrite(case: $row, values: $values);
				$uuid = (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
				if ($value === '' || $uuid === '') {
					$tally['skipped']++;
					continue;
				}

				$todo[$uuid] = $value;
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}//end for

		foreach ($todo as $uuid => $value) {
			$outcome = $this->writeValue(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				uuid: (string)$uuid,
				value: $value
			);
			$tally[$outcome]++;
		}

		return $tally;
	}//end backfill()

	/**
	 * Re-save one case so OpenRegister materialises its value, and report how it went.
	 *
	 * The patch is empty: supplying a readOnly value is refused.
	 * `filled` when the stored case answers the value back. `unconfirmed` when
	 * the write went through and the answer does not carry it, which is a
	 * store that dropped the field and worth a line in the output.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The dossiq register.
	 * @param string $schema        The case schema.
	 * @param string $uuid          The case.
	 * @param string $value         The value the save should materialise.
	 *
	 * @return string `filled`, `unconfirmed` or `failed`.
	 */
	private function writeValue(object $objectService, string $register, string $schema, string $uuid, string $value): string {
		$field = $this->field();
		try {
			$stored = $this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $uuid,
				changes: []
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the ' . $field . ' backfill failed for one case',
				['app' => Application::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		if (($stored[$field] ?? null) === $value) {
			return 'filled';
		}

		return 'unconfirmed';
	}//end writeValue()

	/**
	 * The value every referred row carries, by row uuid.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The dossiq register.
	 * @param string $schema        The schema of the referred rows.
	 *
	 * @return array<string, string> Value by lower-cased uuid; a row without a value is left out.
	 */
	private function valuesByRow(object $objectService, string $register, string $schema): array {
		$property = $this->targetProperty();
		$values = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);

			foreach ($rows as $row) {
				$uuid = (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
				$value = ($row[$property] ?? null);
				if ($uuid !== '' && is_string($value) === true && $value !== '') {
					$values[strtolower($uuid)] = $value;
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $values;
	}//end valuesByRow()
}//end class
