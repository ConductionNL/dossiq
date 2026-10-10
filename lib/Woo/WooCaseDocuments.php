<?php

/**
 * Dossiq Woo case documents
 *
 * THE DOCUMENTS OF A WOO CASE ARE WHERE THE CASE UPLOAD PUTS THEM. A document
 * uploaded on a case is an `informatieobject` (config key
 * `dossier_informatieobject_schema`) carrying the Nextcloud `fileId`, linked
 * to the case by a `zaakinformatieobject` row (`case`, `informatieobject`).
 * The Woo assessment and the publication used to read `document_schema`
 * rows with a `case` field, which nothing on a case writes, so a real case
 * had no documents to assess and nothing to publish (found by the e2e Woo
 * journey, portaliq#1001). Both now ask this class.
 *
 * Rows of `document_schema` that carry `case` are still read, after the
 * informatieobjecten, so an instance that kept documents there loses nothing.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists a case's documents and loads one with its file content.
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 */
class WooCaseDocuments {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register, schemas and OpenRegister.
	 * @param IRootFolder     $rootFolder      Reads a document's file by its id.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ids of every document on a case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, string> Informatieobject ids first, then legacy document ids.
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	public function idsFor(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '' || $caseId === '') {
			return [];
		}

		$ids = [];
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		if ($joinSchema !== '') {
			foreach ($this->search(objectService: $objectService, register: $register, schema: $joinSchema, caseId: $caseId) as $join) {
				$ids[] = (string)($join['informatieobject'] ?? '');
			}
		}

		$legacySchema = $this->settingsService->getConfigValue('document_schema');
		if ($legacySchema !== '') {
			foreach ($this->search(objectService: $objectService, register: $register, schema: $legacySchema, caseId: $caseId) as $document) {
				$ids[] = (string)($document['id'] ?? $document['uuid'] ?? '');
			}
		}

		return array_values(array_unique(array_filter($ids)));
	}//end idsFor()

	/**
	 * One document, with its file content base64-encoded in `content`, or null.
	 *
	 * @param string $documentId An informatieobject id, or a legacy document id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	public function load(string $documentId): ?array {
		$document = $this->meta(documentId: $documentId);
		if ($document === null) {
			return null;
		}

		return $this->withContent(document: $document);
	}//end load()

	/**
	 * One document's row, without reading its file, or null.
	 *
	 * @param string $documentId An informatieobject id, or a legacy document id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
	 */
	public function meta(string $documentId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '' || $documentId === '') {
			return null;
		}

		foreach (['dossier_informatieobject_schema', 'document_schema'] as $key) {
			$schema = $this->settingsService->getConfigValue($key);
			if ($schema === '') {
				continue;
			}

			$document = $this->find(objectService: $objectService, register: $register, schema: $schema, id: $documentId);
			if ($document !== null) {
				return $document;
			}
		}

		return null;
	}//end meta()

	/**
	 * The rows of one schema that name the case.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $schema        The schema.
	 * @param string $caseId        The case uuid.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function search(object $objectService, string $register, string $schema, string $caseId): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['case' => $caseId, '_limit' => 500],
			);
		} catch (Throwable $e) {
			$this->logger->warning('WooCaseDocuments: the case documents could not be read', ['schema' => $schema, 'error' => $e->getMessage()]);
			return [];
		}

		return array_values(array_filter($rows, fn (array $row): bool => (string)($row['case'] ?? '') === $caseId));
	}//end search()

	/**
	 * One row, or null.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $schema        The schema.
	 * @param string $id            The id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(object $objectService, string $register, string $schema, string $id): ?array {
		try {
			return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
		} catch (Throwable $e) {
			return null;
		}
	}//end find()

	/**
	 * The document with its file read into `content`, when it names a file and has none.
	 *
	 * @param array<string, mixed> $document The row.
	 *
	 * @return array<string, mixed>
	 */
	private function withContent(array $document): array {
		$fileId = (int)($document['fileId'] ?? 0);
		if (empty($document['content']) === false || $fileId <= 0) {
			return $document;
		}

		try {
			foreach ($this->rootFolder->getById($fileId) as $node) {
				if ($node instanceof File) {
					$document['content'] = base64_encode($node->getContent());
					break;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('WooCaseDocuments: a document file could not be read', ['fileId' => $fileId, 'error' => $e->getMessage()]);
		}

		return $document;
	}//end withContent()
}//end class
