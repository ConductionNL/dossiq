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

/**
 * Name every case whose `communicationChannel` is not a channel slug a letter can go by.
 *
 * `communicationChannel` was declared a URI while every reader takes it as a
 * slug (`CaseTypeAcknowledgement::channelFor()`), and the portal now writes
 * `portal`, `email` or `post` onto it (portal-contact-channel-follows-the-resident D3).
 * A case created through the ZGW Zaken API can still hold a channel URL, and
 * channel intake takes free text. This step changes nothing: it counts the
 * values outside the slugs and names each case in the log, so a person decides
 * what they mean. It runs once per version behind a persisted key (ADR-106).
 *
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */
class ReportCommunicationChannelValues implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The app-config key that records the step has run.
	 */
	public const DONE_KEY = 'communication_channel_values_reported';

	/**
	 * The value the key holds once the step ran; bump it to run the step again.
	 */
	public const DONE_VERSION = '1';

	/**
	 * The channel slugs the acknowledgement and the portal know.
	 */
	public const SLUGS = ['email', 'portal', 'post', 'website', 'zgw-api'];

	/**
	 * How many cases one page holds.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * @param SettingsService $settingsService OpenRegister access and the configured register.
	 * @param IAppConfig      $appConfig       The version gate.
	 * @param LoggerInterface $logger          Where each case is named.
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
	 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
	 */
	public function getName(): string {
		return 'List Dossiq cases whose communication channel is not a channel slug';
	}//end getName()

	/**
	 * Count and name the cases, once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$output->info('Dossiq communication channels: OpenRegister or the case schema is unavailable; nothing listed.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $register, $schema, $output): void {
				$this->report(objectService: $objectService, register: $register, schema: $schema, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every case and name the ones off the slugs.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param string  $register      The register.
	 * @param string  $schema        The case schema.
	 * @param IOutput $output        The upgrade output.
	 *
	 * @return void
	 */
	private function report(object $objectService, string $register, string $schema, IOutput $output): void {
		$off = 0;
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
				$value = trim((string)($row['communicationChannel'] ?? ''));
				if ($value === '' || in_array($value, self::SLUGS, true) === true) {
					continue;
				}

				$off++;
				$caseId = (string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? ''));
				$this->logger->warning(
					'Dossiq communication channels: case ' . $caseId . ' holds "' . $value . '", which is no channel a letter can go by',
					['app' => Application::APP_ID],
				);
			}

			$page++;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		$output->info('Dossiq communication channels: ' . $off . ' case(s) hold a value outside the channel slugs, named in the log.');
	}//end report()
}//end class
