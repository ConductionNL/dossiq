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
 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
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
 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
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
	 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
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
	 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			$output->info('OpenRegister unavailable, skipping the Woo refusal ground mapping.');
			return;
		}

		try {
			$settled = array_column($this->grounds->listWithRetired(), 'code');
		} catch (Throwable $e) {
			$output->info('Woo refusal ground mapping: the settled list cannot be read yet, skipped until the next upgrade.');
			return;
		}

		foreach (self::TARGET_CONFIG_KEYS as $key) {
			$schema = (string)$this->settingsService->getConfigValue($key);
			if ($schema === '') {
				continue;
			}

			$tally = (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->mapSchema(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					settled: $settled
				)
			);
			$output->info(
				'Woo refusal ground mapping, ' . $key . ': ' . $tally['mapped'] . ' mapped, '
				. $tally['failed'] . ' failed, ' . count($tally['unmapped']) . ' with an unmapped code.'
			);
			foreach ($tally['unmapped'] as $uuid => $codes) {
				$output->warning('Woo refusal ground mapping: ' . $uuid . ' keeps unmapped code(s) ' . implode(', ', $codes) . '.');
			}
		}
	}//end run()

	/**
	 * The changes one row needs, or [] when it needs none.
	 *
	 * @param array<string, mixed> $row     The stored row.
	 * @param array<int, string>   $settled The codes on the settled list.
	 *
	 * @return array<string, mixed> `weigeringsgronden`, `groundsListVersion` and, when any, `groundsUnmapped`.
	 *
	 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-stored-codes-are-mapped-never-guessed-req-wrg-006
	 */
	public function changesFor(array $row, array $settled): array {
		if ((string)($row['groundsListVersion'] ?? '') === WooRefusalGrounds::LIST_VERSION) {
			return [];
		}

		$stored = ($row['weigeringsgronden'] ?? []);
		if (is_array($stored) === false || $stored === []) {
			return [];
		}

		$mapped = [];
		$unmapped = [];
		foreach ($stored as $code) {
			$code = trim((string)$code);
			$target = (self::OLD_TO_SETTLED[$code] ?? null);
			if ($target === null) {
				// Not an old code: kept as it is. A code that is not on the
				// settled list either is flagged rather than guessed at.
				$target = $code;
				if (in_array($code, $settled, true) === false) {
					$unmapped[] = $code;
				}
			}

			if (in_array($target, $mapped, true) === false) {
				$mapped[] = $target;
			}
		}

		$changes = ['weigeringsgronden' => $mapped, 'groundsListVersion' => WooRefusalGrounds::LIST_VERSION];
		if ($unmapped !== []) {
			$changes['groundsUnmapped'] = $unmapped;
		}

		return $changes;
	}//end changesFor()

	/**
	 * Map every row of one schema.
	 *
	 * @param object             $objectService OpenRegister's object service.
	 * @param string             $register      The register.
	 * @param string             $schema        The schema.
	 * @param array<int, string> $settled       The codes on the settled list.
	 *
	 * @return array{mapped: int, failed: int, unmapped: array<string, array<int, string>>} The tally.
	 */
	private function mapSchema(object $objectService, string $register, string $schema, array $settled): array {
		$tally = ['mapped' => 0, 'failed' => 0, 'unmapped' => []];

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$rows = $this->searchObjectsAsArraysUnscoped(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: the Woo refusal ground mapping could not read ' . $schema,
					['app' => Application::APP_ID, 'error' => $e->getMessage()]
				);
				break;
			}

			foreach ($rows as $row) {
				$changes = $this->changesFor(row: $row, settled: $settled);
				$uuid = (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
				if ($changes === [] || $uuid === '') {
					continue;
				}

				try {
					$this->patchObjectAsArray(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						id: $uuid,
						changes: $changes
					);
				} catch (Throwable $e) {
					$this->logger->error(
						'Dossiq: the Woo refusal ground mapping failed for one row',
						['app' => Application::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
					);
					$tally['failed']++;
					continue;
				}

				$tally['mapped']++;
				if (isset($changes['groundsUnmapped']) === true) {
					$tally['unmapped'][$uuid] = $changes['groundsUnmapped'];
				}
			}//end foreach

			if (count($rows) < self::PAGE) {
				break;
			}
		}//end for

		return $tally;
	}//end mapSchema()
}//end class
