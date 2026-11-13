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

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Repair\Support\RepairSchema;
use OCA\Dossiq\Repair\Support\RepairSchemas;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
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
		if ($this->appConfig->getValueString(AppIdentity::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$target = (new RepairSchemas(settingsService: $this->settingsService))->configured(schemaKey: 'result_type_schema');
		if ($target === null) {
			$output->info('Dossiq archival actions: OpenRegister or the result type schema is unavailable; nothing mapped.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $target->objectService,
			work: function () use ($target, $output): void {
				$this->map(target: $target, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every result type and rewrite the legacy values.
	 *
	 * @param RepairSchema $target The schema it walks.
	 * @param IOutput      $output The upgrade output.
	 *
	 * @return void
	 */
	private function map(RepairSchema $target, IOutput $output): void {
		$tally = ['mapped' => 0, 'failed' => 0, 'skipped' => 0];
		foreach ($target->rows(pageSize: self::PAGE_SIZE) as $row) {
			$tally[$this->mapOne(target: $target, row: $row)]++;
		}

		if ($tally['failed'] === 0) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		}

		$output->info('Dossiq archival actions: ' . $tally['mapped'] . ' result type(s) mapped, ' . $tally['failed'] . ' left for the next run.');
	}//end map()

	/**
	 * Map one result type's legacy value.
	 *
	 * @param RepairSchema         $target The schema it walks.
	 * @param array<string, mixed> $row    One stored row.
	 *
	 * @return string 'mapped', 'failed' (logged, and left for the next run) or 'skipped'.
	 */
	private function mapOne(RepairSchema $target, array $row): string {
		$value = ($row['archivalAction'] ?? null);
		if (is_string($value) === false || isset(self::MAP[$value]) === false) {
			return 'skipped';
		}

		$id = $target->idOf(row: $row);
		if ($id === '') {
			return 'skipped';
		}

		try {
			$target->patch(id: $id, changes: ['archivalAction' => self::MAP[$value]]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq archival actions: result type ' . $id . ' could not be written and keeps "' . $value . '" until the next run',
				['app' => AppIdentity::APP_ID, 'error' => $e->getMessage()],
			);
			return 'failed';
		}

		$this->logger->info(
			sprintf('Dossiq archival actions: result type %s now reads "%s"; it held "%s"', $id, self::MAP[$value], $value),
			['app' => AppIdentity::APP_ID],
		);

		return 'mapped';
	}//end mapOne()
}//end class
