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

		$target = (new RepairSchemas(settingsService: $this->settingsService))->configured(schemaKey: 'case_schema');
		if ($target === null) {
			$output->info('Dossiq portal party: OpenRegister or the case schema is unavailable; nothing filled.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $target->objectService,
			work: function () use ($target, $output): void {
				$this->report(target: $target, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every case and fill the party where it is missing.
	 *
	 * @param RepairSchema $target The schema it walks.
	 * @param IOutput      $output The upgrade output.
	 *
	 * @return void
	 */
	private function report(RepairSchema $target, IOutput $output): void {
		$filled = 0;
		foreach ($target->rows(pageSize: self::PAGE_SIZE) as $row) {
			$subject = trim((string)($row['portalSubject'] ?? ''));
			$caseId = $target->idOf(row: $row);
			if ($subject === '' || $caseId === '' || trim((string)($row['portalParty'] ?? '')) !== '') {
				continue;
			}

			try {
				$target->patch(id: $caseId, changes: ['portalParty' => 'subject:' . $subject]);
				$filled++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq portal party: case ' . $caseId . ' could not be given its party: ' . $e->getMessage(),
					['app' => Application::APP_ID],
				);
			}
		}//end foreach

		$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		$output->info('Dossiq portal party: ' . $filled . ' case(s) now name the person they belong to.');
	}//end report()
}//end class
