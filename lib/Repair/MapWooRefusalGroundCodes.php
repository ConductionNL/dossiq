<?php

/**
 * Map the Woo refusal grounds stored on assessments and decisions onto the settled list.
 *
 * Before woo-refusal-grounds-list, dossiq stored its own ten codes (`5.1.1`
 * to `5.1.5`, `5.2.1` to `5.2.5`), several on the wrong article. Design D-2
 * (decision 133) maps each of them onto the settled list. Six of the old
 * codes are also NEW codes with another meaning (5.1.1 and 5.1.2 become group
 * nodes; 5.1.4, 5.1.5, 5.2.1 and 5.2.2 become other grounds), so the step
 * cannot tell an old code from a new one by its value. It marks every row it
 * maps with `groundsListVersion`, and new writes carry the same marker, so a
 * second run changes nothing (REQ-WRG-006).
 *
 * A stored code that is neither an old code nor a settled one is kept as it
 * is, listed in `groundsUnmapped` on the row and in the repair output, for a
 * person to settle.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Maps stored grounds once per row, marked, idempotent and non-fatal.
 *
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
 */
class MapWooRefusalGroundCodes implements IRepairStep {

	use SearchesObjects;

	/**
	 * Design D-2, "dossiq code VALID_WEIGERINGSGRONDEN + woo-verzoek.json (10)".
	 * `5.2.4` maps to 5.2.1 by Ruben's decision, not flagged.
	 *
	 * @var array<string, string>
	 */
	public const OLD_TO_SETTLED = [
		'5.1.1' => '5.1.1.a',
		'5.1.2' => '5.1.1.b',
		'5.1.3' => '5.1.1.c',
		'5.1.4' => '5.2.1',
		'5.1.5' => '5.1.2.e',
		'5.2.1' => '5.1.2.b',
		'5.2.2' => '5.1.2.c',
		'5.2.3' => '5.1.2.d',
		'5.2.4' => '5.2.1',
		'5.2.5' => '5.1.2.i',
	];

	/**
	 * The schemas whose rows store grounds in `weigeringsgronden`, by config key.
	 */
	private const TARGET_CONFIG_KEYS = ['woo_assessment_schema', 'decision_schema'];

	/**
	 * Rows read per page.
	 */
	private const PAGE = 200;

	/**
	 * A ceiling on pages, so a store that ignores `_offset` cannot loop forever.
	 */
	private const MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param WooRefusalGrounds $grounds         The settled list, so a code already on it is kept.
	 * @param LoggerInterface   $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WooRefusalGrounds $grounds,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
	 */
	public function getName(): string {
		return 'Map the Woo refusal grounds stored on assessments and decisions onto the settled list';
	}//end getName()

	/**
	 * Map every unmarked row. Never throws.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			$output->info('OpenRegister unavailable, skipping the Woo refusal ground mapping.');
			return;
		}

		$settled = $this->settledCodes(output: $output);
		if ($settled === null) {
			return;
		}

		foreach (self::TARGET_CONFIG_KEYS as $key) {
			$schema = (string)$this->settingsService->getConfigValue($key);
			if ($schema === '') {
				continue;
			}

			$mapping = new RefusalGroundMapping(objectService: $objectService, register: $register);
			$mapping->configKey = $key;
			$mapping->schema = $schema;
			$mapping->settled = $settled;
			$mapping->tally = (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->mapSchema(mapping: $mapping)
			);
			$this->reportTally(output: $output, mapping: $mapping);
		}
	}//end run()

	/**
	 * The codes on the settled list, or null (said on the output) while it cannot be read yet.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return array<int, string>|null The codes, or null.
	 */
	private function settledCodes(IOutput $output): ?array {
		try {
			return array_column($this->grounds->listWithRetired(), 'code');
		} catch (Throwable $e) {
			$output->info('Woo refusal ground mapping: the settled list cannot be read yet, skipped until the next upgrade.');
			return null;
		}
	}//end settledCodes()

	/**
	 * Print one schema's tally, and every row that keeps an unmapped code.
	 *
	 * @param IOutput              $output  Progress reporting.
	 * @param RefusalGroundMapping $mapping The schema, mapped.
	 *
	 * @return void
	 */
	private function reportTally(IOutput $output, RefusalGroundMapping $mapping): void {
		$tally = $mapping->tally;
		$output->info(
			'Woo refusal ground mapping, ' . $mapping->configKey . ': ' . $tally['mapped'] . ' mapped, '
			. $tally['failed'] . ' failed, ' . count($tally['unmapped']) . ' with an unmapped code.'
		);
		foreach ($tally['unmapped'] as $uuid => $codes) {
			$output->warning('Woo refusal ground mapping: ' . $uuid . ' keeps unmapped code(s) ' . implode(', ', $codes) . '.');
		}
	}//end reportTally()

	/**
	 * The changes one row needs, or [] when it needs none.
	 *
	 * @param array<string, mixed> $row     The stored row.
	 * @param array<int, string>   $settled The codes on the settled list.
	 *
	 * @return array<string, mixed> `weigeringsgronden`, `groundsListVersion` and, when any, `groundsUnmapped`.
	 *
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
	 */
	public function changesFor(array $row, array $settled): array {
		if ((string)($row['groundsListVersion'] ?? '') === WooRefusalGrounds::LIST_VERSION) {
			return [];
		}

		$stored = ($row['weigeringsgronden'] ?? []);
		if (is_array($stored) === false || $stored === []) {
			return [];
		}

		[$mapped, $unmapped] = $this->mappedCodes(stored: $stored, settled: $settled);

		$changes = ['weigeringsgronden' => $mapped, 'groundsListVersion' => WooRefusalGrounds::LIST_VERSION];
		if ($unmapped !== []) {
			$changes['groundsUnmapped'] = $unmapped;
		}

		return $changes;
	}//end changesFor()

	/**
	 * The stored codes on the settled list, each once, and the ones that are on neither list.
	 *
	 * An old code becomes its settled code. Any other code is kept as it is;
	 * one that is not on the settled list either is flagged rather than guessed at.
	 *
	 * @param array<int, mixed>  $stored  The stored codes.
	 * @param array<int, string> $settled The codes on the settled list.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} The mapped codes and the unmapped ones.
	 */
	private function mappedCodes(array $stored, array $settled): array {
		$mapped = [];
		$unmapped = [];
		foreach ($stored as $code) {
			$code = trim((string)$code);
			$target = (self::OLD_TO_SETTLED[$code] ?? null);
			if ($target === null) {
				$target = $code;
				if (in_array($code, $settled, true) === false) {
					$unmapped[] = $code;
				}
			}

			if (in_array($target, $mapped, true) === false) {
				$mapped[] = $target;
			}
		}

		return [$mapped, $unmapped];
	}//end mappedCodes()

	/**
	 * Map every row of one schema, a page at a time.
	 *
	 * @param RefusalGroundMapping $mapping The schema and the settled codes.
	 *
	 * @return array{mapped: int, failed: int, unmapped: array<string, array<int, string>>} The tally.
	 */
	private function mapSchema(RefusalGroundMapping $mapping): array {
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->pageOf(mapping: $mapping, page: $page);
			if ($rows === null) {
				break;
			}

			foreach ($rows as $row) {
				$this->mapRow(mapping: $mapping, row: $row);
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $mapping->tally;
	}//end mapSchema()

	/**
	 * One page of the schema's rows, or null (logged) when the schema cannot be read.
	 *
	 * @param RefusalGroundMapping $mapping The schema.
	 * @param int                  $page    The page, from 0.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null.
	 */
	private function pageOf(RefusalGroundMapping $mapping, int $page): ?array {
		try {
			return $this->searchObjectsAsArraysUnscoped(
				objectService: $mapping->objectService,
				register: $mapping->register,
				schema: $mapping->schema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the Woo refusal ground mapping could not read ' . $mapping->schema,
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end pageOf()

	/**
	 * Map one row and count it: mapped (with its unmapped codes, when any) or failed.
	 *
	 * @param RefusalGroundMapping $mapping The schema, with its tally.
	 * @param array<string, mixed> $row     The stored row.
	 *
	 * @return void
	 */
	private function mapRow(RefusalGroundMapping $mapping, array $row): void {
		$changes = $this->changesFor(row: $row, settled: $mapping->settled);
		$uuid = (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
		if ($changes === [] || $uuid === '') {
			return;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $mapping->objectService,
				register: $mapping->register,
				schema: $mapping->schema,
				id: $uuid,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the Woo refusal ground mapping failed for one row',
				['app' => Application::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			$mapping->tally['failed']++;
			return;
		}

		$mapping->tally['mapped']++;
		if (isset($changes['groundsUnmapped']) === true) {
			$mapping->tally['unmapped'][$uuid] = $changes['groundsUnmapped'];
		}
	}//end mapRow()
}//end class
