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
 * Give every portal case that names its applicant a `portalParty`, once.
 *
 * `portalParty` is the field portaliq's mandate filter reads
 * (site-business-and-authorisation D2). Cases filed before it existed carry
 * only `portalSubject`, so a resident's mandate holder would never see them.
 * This step sets `portalParty` to `subject:<portalSubject>` on every case that
 * has a subject and no party, and leaves every other case alone. It runs once
 * per version behind a persisted key (ADR-106), as the system.
 *
 * @spec openspec/changes/site-business-and-authorisation/specs/portal-contribution/spec.md
 */
class BackfillPortalParty implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The app-config key that records the step has run.
	 */
	public const DONE_KEY = 'portal_party_backfilled';

	/**
	 * The value the key holds once the step ran; bump it to run the step again.
	 */
	public const DONE_VERSION = '1';

	/**
	 * How many cases one page holds.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * @param SettingsService $settingsService OpenRegister access and the configured register.
	 * @param IAppConfig      $appConfig       The version gate.
	 * @param LoggerInterface $logger          Where a case that could not be written is named.
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
	 * @spec openspec/changes/site-business-and-authorisation/specs/portal-contribution/spec.md
	 */
	public function getName(): string {
		return 'Give every Dossiq portal case its portal party';
	}//end getName()

	/**
	 * Fill the party, once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-business-and-authorisation/specs/portal-contribution/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$output->info('Dossiq portal party: OpenRegister or the case schema is unavailable; nothing filled.');
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
	 * Walk every case and fill the party where it is missing.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param string  $register      The register.
	 * @param string  $schema        The case schema.
	 * @param IOutput $output        The upgrade output.
	 *
	 * @return void
	 */
	private function report(object $objectService, string $register, string $schema, IOutput $output): void {
		$filled = 0;
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
				$subject = trim((string)($row['portalSubject'] ?? ''));
				$caseId = (string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? ''));
				if ($subject === '' || $caseId === '' || trim((string)($row['portalParty'] ?? '')) !== '') {
					continue;
				}

				try {
					$this->patchObjectAsArray(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						id: $caseId,
						changes: ['portalParty' => 'subject:' . $subject],
					);
					$filled++;
				} catch (Throwable $e) {
					$this->logger->warning(
						'Dossiq portal party: case ' . $caseId . ' could not be given its party: ' . $e->getMessage(),
						['app' => Application::APP_ID],
					);
				}
			}

			$page++;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		$output->info('Dossiq portal party: ' . $filled . ' case(s) now name the person they belong to.');
	}//end report()
}//end class
