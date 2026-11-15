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
use OCA\Dossiq\Kernel\Contracts\DocumentCopierInterface;
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
class DocumentCopier implements DocumentCopierInterface {

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
			$results[] = $this->copyOne(copy: new DocumentCopy(user: $user, id: (string)$id, targetCaseId: $targetCaseId));
		}

		return $results;
	}//end copyAll()

	/**
	 * Copy one document, refusing one the caller may not read before it is read.
	 *
	 * @param DocumentCopy $copy The caller, the document and the target case.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function copyOne(DocumentCopy $copy): array {
		if ($this->reader->guardReadable(user: $copy->user, infoObjectId: $copy->id) !== null) {
			return self::failed(id: $copy->id, error: 'Insufficient clearance');
		}

		$source = $this->sourceOf(copy: $copy);
		if ($source === null) {
			return self::failed(id: $copy->id, error: 'Document or file not found');
		}

		return $this->written(copy: $copy, source: $source);
	}//end copyOne()

	/**
	 * The document's record and its file as the caller reaches it, null when either is missing.
	 *
	 * @param DocumentCopy $copy The caller, the document and the target case.
	 *
	 * @return array{record: array<string, mixed>, file: File}|null The source.
	 */
	private function sourceOf(DocumentCopy $copy): ?array {
		$record = $this->fileService->getInformatieobject(infoObjectId: $copy->id);
		if ($record === null) {
			return null;
		}

		$file = $this->fileOf(user: $copy->user, fileId: (int)($record['fileId'] ?? 0));
		if ($file === null) {
			return null;
		}

		return ['record' => $record, 'file' => $file];
	}//end sourceOf()

	/**
	 * Write the copy onto the target case and answer how that went.
	 *
	 * @param DocumentCopy                                       $copy   The caller, the document and the target case.
	 * @param array{record: array<string, mixed>, file: File} $source The document's record and file.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function written(DocumentCopy $copy, array $source): array {
		try {
			$copyId = $this->collect(copy: $copy, source: $source);
		} catch (Throwable $e) {
			return self::failed(id: $copy->id, error: $e->getMessage());
		}

		if ($copyId === '') {
			return self::failed(id: $copy->id, error: 'The copy could not be written');
		}

		return ['id' => $copy->id, 'success' => true, 'copyId' => $copyId];
	}//end written()

	/**
	 * Hand the file to the collector with its provenance.
	 *
	 * @param DocumentCopy                                       $copy   The caller, the document and the target case.
	 * @param array{record: array<string, mixed>, file: File} $source The document's record and file.
	 *
	 * @return string The copy's id, or '' when it was not written.
	 */
	private function collect(DocumentCopy $copy, array $source): string {
		[$record, $file] = [$source['record'], $source['file']];
		return $this->collector->collect(
			caseId: $copy->targetCaseId,
			fileName: (string)($record['fileName'] ?? $file->getName()),
			content: $file->getContent(),
			provenance: [
				'kind' => 'copy',
				'document' => $copy->id,
				'by' => $copy->user->getUID(),
				'at' => (new DateTimeImmutable())->format(format: DATE_ATOM),
			],
			mimeType: (string)($record['format'] ?? $file->getMimeType()),
		);
	}//end collect()

	/**
	 * One failed copy.
	 *
	 * @param string $id    The document id.
	 * @param string $error Why it failed.
	 *
	 * @return array{id: string, success: false, error: string} The result.
	 */
	private static function failed(string $id, string $error): array {
		return ['id' => $id, 'success' => false, 'error' => $error];
	}//end failed()

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
