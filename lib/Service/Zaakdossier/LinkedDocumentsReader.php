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

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Files\Folder;
use OCP\IURLGenerator;
use Throwable;

/**
 * The documents a case is joined to whose file lives in another case's folder.
 *
 * The Files tab shows the case's own folder; a document joined from
 * elsewhere (REQ-DPR-004) is not a node in it, so the tab asks this reader
 * for the linked rows: name, type, size, where to open and download it, and
 * which case holds the file. A document whose file IS in the folder is not
 * listed; the browser already shows it.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class LinkedDocumentsReader {
	use SearchesObjects;

	/**
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 * @param DocumentRecordStore $store Records, joins and cases.
	 * @param DocumentProjectionService $projection Object folders.
	 * @param IURLGenerator $urlGenerator The open, download and case links.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DocumentRecordStore $store,
		private readonly DocumentProjectionService $projection,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * The linked rows of a case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>> Items for the files browser's `linkedItems`.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function linkedDocuments(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		if ($objectService === null || $register === '' || $joinSchema === '') {
			return [];
		}

		$caseFolder = $this->projection->folderOf(objectId: $caseId);
		$joins = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $joinSchema,
			filters: ['case' => $caseId, '_limit' => 200],
		);

		$items = [];
		foreach ($joins as $join) {
			$item = $this->itemFor(recordId: trim((string)($join['informatieobject'] ?? '')), caseId: $caseId, caseFolder: $caseFolder);
			if ($item !== null) {
				$items[] = $item;
			}
		}

		return $items;
	}//end linkedDocuments()

	/**
	 * One linked row, or null when the record's file is in this case's folder or has no file.
	 *
	 * @param string $recordId The record uuid.
	 * @param string $caseId This case.
	 * @param Folder|null $caseFolder This case's folder, when it has one.
	 *
	 * @return array<string, mixed>|null The item.
	 */
	private function itemFor(string $recordId, string $caseId, ?Folder $caseFolder): ?array {
		if ($recordId === '') {
			return null;
		}

		$record = $this->store->findRecord(recordId: $recordId);
		$fileId = (int)($record['fileId'] ?? 0);
		if ($record === null || $fileId <= 0) {
			return null;
		}

		if ($caseFolder !== null && $this->holds(folder: $caseFolder, fileId: $fileId) === true) {
			return null;
		}

		$owner = $this->owningCase(recordId: $recordId, notCaseId: $caseId);
		$item = [
			'id' => $recordId,
			'name' => (string)($record['fileName'] ?? ($record['title'] ?? $recordId)),
			'mime' => (string)($record['format'] ?? ''),
			'size' => (int)($record['bestandsomvang'] ?? 0),
			'href' => $this->urlGenerator->linkToRoute('files.viewcontroller.showFile', ['fileid' => $fileId]),
			'downloadHref' => $this->urlGenerator->linkToRoute('dossiq.zaakdossierDownload.downloadZgwDocumenten', ['uuid' => $recordId]),
			'note' => '',
			'noteHref' => '',
		];
		if ($owner !== null) {
			$item['note'] = 'In ' . (string)($owner['identifier'] ?? ($owner['title'] ?? $owner['id']));
			$item['noteHref'] = rtrim($this->urlGenerator->linkToRoute('dossiq.dashboard.page'), '/') . '/cases/' . (string)$owner['id'];
		}

		return $item;
	}//end itemFor()

	/**
	 * Whether a folder holds a file id, anywhere beneath it.
	 *
	 * @param Folder $folder The folder.
	 * @param int $fileId The file id.
	 *
	 * @return bool True when the file is in the folder.
	 */
	private function holds(Folder $folder, int $fileId): bool {
		try {
			return $folder->getById($fileId) !== [];
		} catch (Throwable) {
			return false;
		}
	}//end holds()

	/**
	 * The case whose folder holds the record's file: its earliest join to another case.
	 *
	 * @param string $recordId The record uuid.
	 * @param string $notCaseId The case asking, excluded.
	 *
	 * @return array<string, mixed>|null The owning case row.
	 */
	private function owningCase(string $recordId, string $notCaseId): ?array {
		$joins = $this->store->joinsFor(recordId: $recordId);
		usort(
			$joins,
			static fn (array $a, array $b): int => strcmp((string)($a['registrationDate'] ?? ''), (string)($b['registrationDate'] ?? ''))
		);
		foreach ($joins as $join) {
			$otherId = trim((string)($join['case'] ?? ''));
			if ($otherId === '' || $otherId === $notCaseId) {
				continue;
			}

			$owner = $this->store->findCase(caseId: $otherId);
			if ($owner !== null) {
				return $owner;
			}
		}

		return null;
	}//end owningCase()
}//end class
