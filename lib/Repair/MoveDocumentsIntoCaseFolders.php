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
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Move every existing document's file into the folder of the case that owns it.
 *
 * Before documents-live-on-the-case a document's file sat in its own
 * record's folder. This step walks every informatieobject with a file, finds
 * the case of its earliest join, and moves the file into that case's folder
 * through the same code the ZRC runs on a join, so `fileId` follows. It runs
 * once per version behind a persisted key (ADR-106), skips a file already
 * under a case folder, and names a record whose file cannot be found rather
 * than inventing one. Re-running it moves nothing.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class MoveDocumentsIntoCaseFolders implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The app-config key that records the step has run.
	 */
	public const DONE_KEY = 'documents_on_case_migrated';

	/**
	 * The value the key holds once the step ran; bump it to run the step again.
	 */
	public const DONE_VERSION = '1';

	/**
	 * How many records one page holds.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * @param SettingsService $settingsService OpenRegister access and the configured register.
	 * @param DocumentRecordStore $store Records and joins.
	 * @param DocumentProjectionService $projection The move itself.
	 * @param IAppConfig $appConfig The version gate.
	 * @param LoggerInterface $logger Where each skipped record is named.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DocumentRecordStore $store,
		private readonly DocumentProjectionService $projection,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name the upgrade log prints.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function getName(): string {
		return 'Move every Dossiq document file into the folder of the case that owns it';
	}//end getName()

	/**
	 * Move the files, once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('Dossiq documents: OpenRegister unavailable; documents stay where they are.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->moveAll(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every record with a file and move it into its owning case.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 */
	private function moveAll(object $objectService, IOutput $output): void {
		$register = $this->settingsService->getConfigValue('register');
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($register === '' || $infoSchema === '') {
			$output->info('Dossiq documents: dossier schemas not configured; documents stay where they are.');
			return;
		}

		$moved = 0;
		$skipped = 0;
		$missing = 0;
		$page = 1;
		$full = true;
		while ($full === true) {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $infoSchema,
				filters: ['_limit' => self::PAGE_SIZE, '_page' => $page],
			);
			$full = (count($rows) === self::PAGE_SIZE);
			foreach ($rows as $row) {
				$outcome = $this->moveOne(row: $row);
				if ($outcome === 'moved') {
					$moved++;
				}

				if ($outcome === 'skipped') {
					$skipped++;
				}

				if ($outcome === 'missing') {
					$missing++;
				}
			}

			$page++;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		$output->info(
			'Dossiq documents: moved ' . $moved . ' file(s) into their case, left ' . $skipped
			. ' already home or without a case, ' . $missing . ' named in the log.'
		);
	}//end moveAll()

	/**
	 * Move one record's file, when it has one and a case to go to.
	 *
	 * @param array<string, mixed> $row The record row.
	 *
	 * @return string moved, skipped or missing.
	 */
	private function moveOne(array $row): string {
		$recordId = trim((string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? '')));
		$fileName = (string)($row['fileName'] ?? '');
		if ($recordId === '' || $fileName === '' || (int)($row['fileId'] ?? 0) <= 0) {
			return 'skipped';
		}

		$ownFolder = $this->projection->folderOf(objectId: $recordId);
		if ($ownFolder === null || $ownFolder->nodeExists($fileName) === false) {
			// Already under a case folder, which is what a second run sees.
			return 'skipped';
		}

		$caseId = $this->owningCase(recordId: $recordId);
		if ($caseId === '') {
			$this->logger->warning(
				'Dossiq documents: ' . $recordId . ' (' . $fileName . ') is joined to no case; its file stays in its own folder',
				['app' => Application::APP_ID],
			);

			return 'missing';
		}

		try {
			if ($this->projection->homeDocument(recordId: $recordId, caseId: $caseId) === true) {
				return 'moved';
			}

			return 'skipped';
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq documents: ' . $recordId . ' (' . $fileName . ') could not move into case ' . $caseId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e],
			);

			return 'missing';
		}
	}//end moveOne()

	/**
	 * The case of a record's earliest join, by registration date.
	 *
	 * @param string $recordId The record uuid.
	 *
	 * @return string The case uuid, '' when the record is joined to none.
	 */
	private function owningCase(string $recordId): string {
		$joins = $this->store->joinsFor(recordId: $recordId);
		usort(
			$joins,
			static fn (array $a, array $b): int => strcmp((string)($a['registrationDate'] ?? ''), (string)($b['registrationDate'] ?? ''))
		);
		foreach ($joins as $join) {
			$caseId = trim((string)($join['case'] ?? ''));
			if ($caseId !== '') {
				return $caseId;
			}
		}

		return '';
	}//end owningCase()
}//end class
