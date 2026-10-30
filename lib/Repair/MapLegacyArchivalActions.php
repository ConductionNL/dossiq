<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Map the archival actions stored as `retain` and `destroy` onto the enum.
 *
 * The admin settings case type editor wrote `retain` and `destroy` to a result
 * type's `archivalAction`, which the resultType schema declares as
 * `bewaren | vernietigen | blijvend_bewaren`. This step rewrites those two
 * values to `bewaren` and `vernietigen`, reading and writing as the system
 * user. Every other value is left alone, so a second run changes nothing. It
 * runs once per version behind a persisted key, and a result type that cannot
 * be written is named and left for the next run.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class MapLegacyArchivalActions implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The app-config key that records the step has run.
	 */
	public const DONE_KEY = 'legacy_archival_actions_mapped';

	/**
	 * The value the key holds once the step ran; bump it to run the step again.
	 */
	public const DONE_VERSION = '1';

	/**
	 * The stored legacy value and the enum value it becomes.
	 *
	 * @var array<string, string>
	 */
	public const MAP = [
		'retain'  => 'bewaren',
		'destroy' => 'vernietigen',
	];

	/**
	 * How many result types one page holds.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access and the configured register.
	 * @param IAppConfig      $appConfig       The version gate.
	 * @param LoggerInterface $logger          Where each result type is named.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name the upgrade log prints.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function getName(): string {
		return 'Map Dossiq result type archival actions retain and destroy onto bewaren and vernietigen';
	}//end getName()

	/**
	 * Map the result types, once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('result_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$output->info('Dossiq archival actions: OpenRegister or the result type schema is unavailable; nothing mapped.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $register, $schema, $output): void {
				$this->map(objectService: $objectService, register: $register, schema: $schema, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every result type and rewrite the legacy values.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param string  $register      The register.
	 * @param string  $schema        The result type schema.
	 * @param IOutput $output        The upgrade output.
	 *
	 * @return void
	 */
	private function map(object $objectService, string $register, string $schema, IOutput $output): void {
		$mapped = 0;
		$failed = 0;
		$page = 1;
		$full = true;
		while ($full === true) {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE_SIZE, '_page' => $page],
			);
			$full = (count($rows) === self::PAGE_SIZE);
			foreach ($rows as $row) {
				$value = ($row['archivalAction'] ?? null);
				if (is_string($value) === false || isset(self::MAP[$value]) === false) {
					continue;
				}

				$id = (string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? ''));
				if ($id === '') {
					continue;
				}

				try {
					$this->patchObjectAsArray(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						id: $id,
						changes: ['archivalAction' => self::MAP[$value]]
					);
				} catch (Throwable $e) {
					$failed++;
					$this->logger->warning(
						'Dossiq archival actions: result type ' . $id . ' could not be written and keeps "' . $value . '" until the next run',
						['app' => Application::APP_ID, 'error' => $e->getMessage()],
					);
					continue;
				}

				$mapped++;
				$this->logger->info(
					sprintf('Dossiq archival actions: result type %s now reads "%s"; it held "%s"', $id, self::MAP[$value], $value),
					['app' => Application::APP_ID],
				);
			}//end foreach

			$page++;
		}//end while

		if ($failed === 0) {
			$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		}

		$output->info('Dossiq archival actions: ' . $mapped . ' result type(s) mapped, ' . $failed . ' left for the next run.');
	}//end map()
}//end class
