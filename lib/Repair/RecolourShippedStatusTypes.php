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
use OCA\Dossiq\Service\StatusTypePalette;
use OCA\Dossiq\Service\Support\SearchesObjects;
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
	use SearchesObjects;

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
	 * @param SettingsService   $settingsService OpenRegister access and the configured register.
	 * @param IAppConfig        $appConfig       The version gate.
	 * @param StatusTypePalette $palette         The board colour per status.
	 * @param LoggerInterface   $logger          Where each row is named.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
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
		if ($this->appConfig->getValueString(Application::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('status_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$output->info('Dossiq status colours: OpenRegister or the status type schema is unavailable; nothing recoloured.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $register, $schema, $output): void {
				$this->recolour(objectService: $objectService, register: $register, schema: $schema, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every status type and recolour the shipped ones that still hold a shipped colour.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param string  $register      The register.
	 * @param string  $schema        The status type schema.
	 * @param IOutput $output        The upgrade output.
	 *
	 * @return void
	 */
	private function recolour(object $objectService, string $register, string $schema, IOutput $output): void {
		$changed = 0;
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
				$self = ($row['@self'] ?? []);
				if (is_array($self) === false || ($self['owner'] ?? null) !== self::SYSTEM_OWNER) {
					continue;
				}

				$colour = $this->palette->recolour(row: $row);
				$rowId = (string)($row['id'] ?? ($self['id'] ?? ''));
				if ($colour === null || $rowId === '') {
					continue;
				}

				try {
					$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $rowId, changes: ['colour' => $colour]);
				} catch (Throwable $e) {
					$failed++;
					$this->logger->warning(
						'Dossiq status colours: status ' . $rowId . ' could not be recoloured and keeps its colour until the next run',
						['app' => Application::APP_ID, 'error' => $e->getMessage()],
					);
					continue;
				}

				$changed++;
				$this->logger->info(
					sprintf('Dossiq status colours: status %s (%s) is now %s', $rowId, (string)($row['name'] ?? ''), $colour),
					['app' => Application::APP_ID],
				);
			}//end foreach

			$page++;
		}//end while

		if ($failed === 0) {
			$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		}

		$output->info('Dossiq status colours: ' . $changed . ' shipped status(es) took their board colour, ' . $failed . ' left for the next run.');
	}//end recolour()
}//end class
