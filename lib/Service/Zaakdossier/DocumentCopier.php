<?php

/**
 * Dossiq document copier
 *
 * Copies documents of one case to another: the bytes go into the target
 * case's folder as a new document on that case, with where they came from
 * (`provenance`). Generic: any case copies any document the caller may read.
 * Whether the caller may add documents to the TARGET case is the caller's
 * check, made before this runs.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/documents-copy-to-case/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use DateTimeImmutable;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Throwable;

/**
 * Copies readable documents to another case, one result per document.
 *
 * @spec openspec/specs/documents-copy-to-case/spec.md#requirement-documents-are-copied-to-a-case-the-caller-may-add-to
 */
class DocumentCopier {

	/**
	 * Constructor.
	 *
	 * @param InformatieobjectReader $reader      The per-document read check.
	 * @param ZaakdossierService     $fileService The document records.
	 * @param IRootFolder            $rootFolder  The files, read as the caller.
	 * @param CaseFileCollector      $collector   Writes bytes as a document on a case, with provenance.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly InformatieobjectReader $reader,
		private readonly ZaakdossierService $fileService,
		private readonly IRootFolder $rootFolder,
		private readonly CaseFileCollector $collector,
	) {
	}//end __construct()

	/**
	 * Copy each document to the target case.
	 *
	 * @param IUser         $user         The caller.
	 * @param array<mixed>  $ids          The document (informatieobject) ids.
	 * @param string        $targetCaseId The case the copies go to.
	 *
	 * @return list<array<string, mixed>> Per id: `id`, `success`, and `copyId` or `error`.
	 *
	 * @spec openspec/specs/documents-copy-to-case/spec.md#requirement-documents-are-copied-to-a-case-the-caller-may-add-to
	 */
	public function copyAll(IUser $user, array $ids, string $targetCaseId): array {
		$results = [];
		foreach ($ids as $id) {
			$results[] = $this->copyOne(user: $user, id: (string)$id, targetCaseId: $targetCaseId);
		}

		return $results;
	}//end copyAll()

	/**
	 * Copy one document.
	 *
	 * @param IUser  $user         The caller.
	 * @param string $id           The document id.
	 * @param string $targetCaseId The case the copy goes to.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function copyOne(IUser $user, string $id, string $targetCaseId): array {
		if ($this->reader->guardReadable(user: $user, infoObjectId: $id) !== null) {
			return ['id' => $id, 'success' => false, 'error' => 'Insufficient clearance'];
		}

		$record = $this->fileService->getInformatieobject(infoObjectId: $id);
		$file = null;
		if ($record !== null) {
			$file = $this->fileOf(user: $user, fileId: (int)($record['fileId'] ?? 0));
		}

		if ($record === null || $file === null) {
			return ['id' => $id, 'success' => false, 'error' => 'Document or file not found'];
		}

		try {
			$copyId = $this->collector->collect(
				caseId: $targetCaseId,
				fileName: (string)($record['fileName'] ?? $file->getName()),
				content: $file->getContent(),
				provenance: [
					'kind' => 'copy',
					'document' => $id,
					'by' => $user->getUID(),
					'at' => (new DateTimeImmutable())->format(format: DATE_ATOM),
				],
				mimeType: (string)($record['format'] ?? $file->getMimeType()),
			);
		} catch (Throwable $e) {
			return ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
		}

		if ($copyId === '') {
			return ['id' => $id, 'success' => false, 'error' => 'The copy could not be written'];
		}

		return ['id' => $id, 'success' => true, 'copyId' => $copyId];
	}//end copyOne()

	/**
	 * The file as the caller reaches it, null when they cannot.
	 *
	 * @param IUser $user   The caller.
	 * @param int   $fileId The file id.
	 *
	 * @return File|null The file.
	 */
	private function fileOf(IUser $user, int $fileId): ?File {
		if ($fileId <= 0) {
			return null;
		}

		$node = $this->rootFolder->getUserFolder(userId: $user->getUID())->getFirstNodeById(id: $fileId);
		if ($node instanceof File) {
			return $node;
		}

		return null;
	}//end fileOf()
}//end class
