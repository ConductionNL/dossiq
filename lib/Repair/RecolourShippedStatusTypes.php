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
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Repair\Support\RepairSchema;
use OCA\Dossiq\Repair\Support\RepairSchemas;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\StatusTypePalette;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Give the statuses dossiq shipped the colour their boards draw.
 *
 * The shipped status types held no colour, the schema's grey, or an older
 * shipped colour, so every status pill read grey or orange where the boards
 * draw received blue, in progress purple, a decision teal and done green.
 * This step writes the board colour onto each status dossiq seeded (owner
 * `__system__`) while it still holds one of those; a colour an administrator
 * chose stays. It runs once per version behind a persisted key, and a row that
 * cannot be written is named and left for the next run.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
 */
class RecolourShippedStatusTypes implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * The app-config key that records the step has run.
	 */
	public const DONE_KEY = 'status_types_recoloured';

	/**
	 * The value the key holds once the step ran; bump it to run the step again.
	 */
	public const DONE_VERSION = '1';

	/**
	 * The owner of the rows dossiq seeded.
	 */
	private const SYSTEM_OWNER = '__system__';

	/**
	 * How many status types one page holds.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface   $settingsService OpenRegister access and the configured register.
	 * @param IAppConfig        $appConfig       The version gate.
	 * @param StatusTypePalette $palette         The board colour per status.
	 * @param LoggerInterface   $logger          Where each row is named.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly StatusTypePalette $palette,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name the upgrade log prints.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
	 */
	public function getName(): string {
		return 'Give the statuses Dossiq ships the colour their boards draw';
	}//end getName()

	/**
	 * Recolour the shipped statuses, once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(AppIdentity::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$target = (new RepairSchemas(settingsService: $this->settingsService))->configured(schemaKey: 'status_type_schema');
		if ($target === null) {
			$output->info('Dossiq status colours: OpenRegister or the status type schema is unavailable; nothing recoloured.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $target->objectService,
			work: function () use ($target, $output): void {
				$this->recolour(target: $target, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every status type and recolour the shipped ones that still hold a shipped colour.
	 *
	 * @param RepairSchema $target The schema it walks.
	 * @param IOutput      $output The upgrade output.
	 *
	 * @return void
	 */
	private function recolour(RepairSchema $target, IOutput $output): void {
		$tally = ['changed' => 0, 'failed' => 0, 'skipped' => 0];
		foreach ($target->rows(pageSize: self::PAGE_SIZE) as $row) {
			$tally[$this->recolourOne(target: $target, row: $row)]++;
		}

		if ($tally['failed'] === 0) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		}

		$output->info('Dossiq status colours: ' . $tally['changed'] . ' shipped status(es) took their board colour, '
			. $tally['failed'] . ' left for the next run.');
	}//end recolour()

	/**
	 * Give one shipped status type its board colour.
	 *
	 * @param RepairSchema         $target The schema it walks.
	 * @param array<string, mixed> $row    One stored row.
	 *
	 * @return string 'changed', 'failed' (logged, and left for the next run) or 'skipped'.
	 */
	private function recolourOne(RepairSchema $target, array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false || ($self['owner'] ?? null) !== self::SYSTEM_OWNER) {
			return 'skipped';
		}

		$colour = $this->palette->recolour(row: $row);
		$rowId = (string)($row['id'] ?? ($self['id'] ?? ''));
		if ($colour === null || $rowId === '') {
			return 'skipped';
		}

		try {
			$target->patch(id: $rowId, changes: ['colour' => $colour]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq status colours: status ' . $rowId . ' could not be recoloured and keeps its colour until the next run',
				['app' => AppIdentity::APP_ID, 'error' => $e->getMessage()],
			);
			return 'failed';
		}

		$this->logger->info(
			sprintf('Dossiq status colours: status %s (%s) is now %s', $rowId, (string)($row['name'] ?? ''), $colour),
			['app' => AppIdentity::APP_ID],
		);

		return 'changed';
	}//end recolourOne()
}//end class
