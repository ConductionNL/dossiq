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
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keep one document record per file: the first one written.
 *
 * A file attached to a document's own folder was once projected as a second
 * record of that file, because the document's uuid passed for a case. That
 * twin has no sender and the install day as its date, and it is joined to the
 * document and, after its file moved, to the case. Each later record of a
 * file goes with its joins; one with a folder of its own stays, since it may
 * hold files of its own.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class RemoveDocumentRecordTwins implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	public const DONE_KEY = 'document_record_twins_removed';

	public const DONE_VERSION = '1';

	private const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register and schema configuration.
	 * @param DocumentRecordStore $store The record store.
	 * @param IAppConfig $appConfig Where the step records that it ran.
	 * @param LoggerInterface $logger Where a record that could not go is named.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DocumentRecordStore $store,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * @return string The step's name.
	 */
	public function getName(): string {
		return 'Keep one Dossiq document record per file';
	}//end getName()

	/**
	 * @param IOutput $output The repair output.
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
			$output->info('Dossiq documents: OpenRegister unavailable; document records stay as they are.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->removeAll(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * Remove every later record of a file.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 */
	private function removeAll(object $objectService, IOutput $output): void {
		$register = $this->settingsService->getConfigValue('register');
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($register === '' || $infoSchema === '') {
			$output->info('Dossiq documents: dossier schemas not configured; document records stay as they are.');
			return;
		}

		$removed = 0;
		$kept = 0;
		foreach ($this->laterRecords(byFile: $this->recordsByFile(objectService: $objectService, register: $register, schema: $infoSchema)) as $row) {
			if ($this->removeOne(row: $row) === true) {
				$removed++;
				continue;
			}

			$kept++;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		$output->info(
			'Dossiq documents: removed ' . $removed . ' second record(s) of a file, kept ' . $kept . ' named in the log.'
		);
	}//end removeAll()

	/**
	 * Every record that names a file, grouped by that file's id.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register The register.
	 * @param string $schema The document schema.
	 *
	 * @return array<int, array<int, array<string, mixed>>> file id => its records.
	 */
	private function recordsByFile(object $objectService, string $register, string $schema): array {
		$byFile = [];
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
				$fileId = (int)($row['fileId'] ?? 0);
				if ($fileId > 0) {
					$byFile[$fileId][] = $row;
				}
			}

			$page++;
		}

		return $byFile;
	}//end recordsByFile()

	/**
	 * The records after the first of each file, by the time they were written.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $byFile file id => its records.
	 *
	 * @return array<int, array<string, mixed>> The later records.
	 */
	private function laterRecords(array $byFile): array {
		$later = [];
		foreach ($byFile as $rows) {
			if (count($rows) < 2) {
				continue;
			}

			usort(
				$rows,
				fn (array $a, array $b): int => [$this->selfOf(row: $a, key: 'created'), $this->selfOf(row: $a, key: 'id')]
					<=> [$this->selfOf(row: $b, key: 'created'), $this->selfOf(row: $b, key: 'id')]
			);
			array_push($later, ...array_slice($rows, 1));
		}

		return $later;
	}//end laterRecords()

	/**
	 * Remove one later record and its joins.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return bool True when it went.
	 */
	private function removeOne(array $row): bool {
		$recordId = trim((string)($row['id'] ?? $this->selfOf(row: $row, key: 'id')));
		if ($recordId === '' || $this->selfOf(row: $row, key: 'folder') !== '') {
			$this->logger->warning(
				'Dossiq documents: ' . $recordId . ' names file ' . (string)($row['fileId'] ?? '')
				. ' as an earlier record does, but has a folder of its own; it stays',
				['app' => Application::APP_ID],
			);

			return false;
		}

		try {
			$this->store->deleteJoins(recordId: $recordId);
			$this->store->deleteRecord(recordId: $recordId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq documents: second record ' . $recordId . ' of file ' . (string)($row['fileId'] ?? '') . ' could not go: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e],
			);

			return false;
		}

		return true;
	}//end removeOne()

	/**
	 * One `@self` field as a string.
	 *
	 * @param array<string, mixed> $row The record.
	 * @param string $key The field.
	 *
	 * @return string The value, '' when absent.
	 */
	private function selfOf(array $row, string $key): string {
		$value = ((array)($row['@self'] ?? []))[$key] ?? '';
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end selfOf()
}//end class
