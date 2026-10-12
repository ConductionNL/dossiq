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
use OCA\Dossiq\Service\CommunicationChannel;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turn every stored `communicationChannel` into a channel slug.
 *
 * Decision 171 (Q-dossiq-L2-3): `case.communicationChannel` is a slug enum
 * (email, portal, post, website, zgw-api). Cases written before it can hold a
 * ZGW communicatiekanaal URL or the words of an older channel intake. This
 * step maps each such value with CommunicationChannel: a URL becomes the slug
 * an administrator mapped it to, else `zgw-api`; known words become their
 * slug; anything else leaves the channel empty. The value it replaced is kept
 * in `communicationChannelSource`, and every converted case is named in the
 * log. It runs once per version behind a persisted key (ADR-106), and a case
 * that cannot be written is named and left for the next run.
 *
 * It replaces ReportCommunicationChannelValues, which only listed the values
 * and was never registered.
 *
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */
class NormaliseCommunicationChannelValues implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The app-config key that records the step has run.
	 */
	public const DONE_KEY = 'communication_channel_values_normalised';

	/**
	 * The value the key holds once the step ran; bump it to run the step again.
	 */
	public const DONE_VERSION = '1';

	/**
	 * How many cases one page holds.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settingsService OpenRegister access and the configured register.
	 * @param IAppConfig           $appConfig       The version gate.
	 * @param CommunicationChannel $channels        The mapping onto slugs.
	 * @param LoggerInterface      $logger          Where each case is named.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly CommunicationChannel $channels,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name the upgrade log prints.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Turn every Dossiq case communication channel into a channel slug';
	}//end getName()

	/**
	 * Convert the cases, once.
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
			$output->info('Dossiq communication channels: OpenRegister or the case schema is unavailable; nothing converted.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $register, $schema, $output): void {
				$this->convert(objectService: $objectService, register: $register, schema: $schema, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every case and convert the ones off the slugs.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param string  $register      The register.
	 * @param string  $schema        The case schema.
	 * @param IOutput $output        The upgrade output.
	 *
	 * @return void
	 */
	private function convert(object $objectService, string $register, string $schema, IOutput $output): void {
		$converted = 0;
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
				$value = ($row['communicationChannel'] ?? null);
				$isSlug = in_array($value, CommunicationChannel::SLUGS, true);
				if (is_string($value) === false || trim($value) === '' || $isSlug === true) {
					continue;
				}

				$caseId = (string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? ''));
				if ($caseId === '') {
					continue;
				}

				$changes = $this->channels->normalise(value: $value);
				try {
					$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId, changes: $changes);
				} catch (Throwable $e) {
					$failed++;
					$this->logger->warning(
						'Dossiq communication channels: case ' . $caseId . ' could not be converted and keeps its value until the next run',
						['app' => Application::APP_ID, 'error' => $e->getMessage()],
					);
					continue;
				}

				$converted++;
				$this->logger->info(
					sprintf('Dossiq channels: case %s now reads "%s"; it held "%s"', $caseId, (string)($changes['communicationChannel'] ?? ''), $value),
					['app' => Application::APP_ID],
				);
			}//end foreach

			$page++;
		}//end while

		if ($failed === 0) {
			$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		}

		$output->info('Dossiq communication channels: ' . $converted . ' case(s) converted to a channel slug, ' . $failed . ' left for the next run.');
	}//end convert()
}//end class
