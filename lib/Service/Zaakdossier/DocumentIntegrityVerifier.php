<?php

/**
 * Dossiq document integrity verifier
 *
 * Checks stored checksums again. Every document gets a sha256 of its file
 * when it is stored (`integrity.value`, `integrity.date`); this hashes the
 * file now, compares, and records the outcome on the document as
 * `integrity.status` (unchanged, changed, missing, unchecked) with the time
 * of the check (`integrity.checkedAt`). The stored hash and its date stay as
 * they were, so a changed file never rewrites the evidence it is held to.
 * A document whose file was never hashed (one written before its file, such
 * as an example set's) gets its hash on its first check, dated that check.
 * Generic: every document of every case type.
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
 * @spec openspec/specs/document-integrity-check/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use Throwable;

/**
 * Re-verifies the stored checksums of documents, a batch at a time.
 *
 * @spec openspec/specs/document-integrity-check/spec.md#requirement-stored-checksums-are-checked-again-and-the-check-is-recorded
 */
class DocumentIntegrityVerifier {

	use SearchesObjects;

	/**
	 * The app config key holding where the next run starts.
	 */
	private const CURSOR_KEY = 'integrity_check_offset';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService OpenRegister access and the configured register and schema.
	 * @param IRootFolder      $rootFolder      The files, read as the system.
	 * @param DocumentDefaults $defaults        Hashes a file the way it was hashed when stored.
	 * @param IAppConfig       $appConfig       Keeps where the next run starts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IRootFolder $rootFolder,
		private readonly DocumentDefaults $defaults,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Check the next batch of documents and record each outcome.
	 *
	 * @param int $batch How many documents one run reads.
	 *
	 * @return int How many documents were checked or got their first hash.
	 *
	 * @spec openspec/specs/document-integrity-check/spec.md#requirement-stored-checksums-are-checked-again-and-the-check-is-recorded
	 */
	public function verifyNext(int $batch = 200): int {
		$store = $this->documentStore();
		if ($store === null) {
			return 0;
		}

		$checked = 0;
		foreach ($this->nextBatch(store: $store, batch: $batch) as $row) {
			if ($this->verifyOne(store: $store, row: $row) === true) {
				$checked++;
			}
		}

		return $checked;
	}//end verifyNext()

	/**
	 * The object service, register and document schema, null when any is missing.
	 *
	 * @return array{objectService: object, register: string, schema: string}|null The store.
	 */
	private function documentStore(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register      = $this->settingsService->getConfigValue('register');
		$schema        = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end documentStore()

	/**
	 * The next batch of documents from the cursor, moving the cursor on, or back to the start after the last.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store The document store.
	 * @param int                                                             $batch How many documents to read.
	 *
	 * @return array<int, array<string, mixed>> The documents.
	 */
	private function nextBatch(array $store, int $batch): array {
		$offset = $this->appConfig->getValueInt(Application::APP_ID, self::CURSOR_KEY, 0);
		$rows   = $this->searchObjectsAsArraysUnscoped(
			objectService: $store['objectService'],
			register: $store['register'],
			schema: $store['schema'],
			filters: ['_limit' => $batch, '_offset' => $offset],
		);

		$next = ($offset + count($rows));
		if (count($rows) < $batch) {
			$next = 0;
		}

		$this->appConfig->setValueInt(Application::APP_ID, self::CURSOR_KEY, $next);

		return $rows;
	}//end nextBatch()

	/**
	 * Check one document, or give one whose file was never hashed its first hash.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store The document store.
	 * @param array<string, mixed>                                            $row   The document.
	 *
	 * @return bool Whether the document was checked.
	 *
	 * @spec openspec/changes/a-document-without-a-checksum-gets-one/specs/document-integrity-check/spec.md#requirement-a-document-without-a-stored-checksum-gets-one-on-its-first-check
	 */
	private function verifyOne(array $store, array $row): bool {
		$check = $this->checkOf(row: $row);
		if ($check === null) {
			return false;
		}

		$check['integrity']['status'] = $this->statusOf(stored: (string)$check['integrity']['value'], fileId: (int)($row['fileId'] ?? 0));
		try {
			$this->recordCheck(store: $store, check: $check);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end verifyOne()

	/**
	 * The document's id and the integrity to check it against, null when it has no id or nothing to check.
	 *
	 * The stored integrity, or its file's first hash when it never had one.
	 *
	 * @param array<string, mixed> $row The document.
	 *
	 * @return array{id: string, integrity: array<string, mixed>}|null The check.
	 */
	private function checkOf(array $row): ?array {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if ($id === '') {
			return null;
		}

		$integrity = $this->integrityOf(row: $row);
		if ($integrity === []) {
			return null;
		}

		return ['id' => $id, 'integrity' => $integrity];
	}//end checkOf()

	/**
	 * The document's stored integrity, or its file's first hash when it never had one; [] when neither.
	 *
	 * @param array<string, mixed> $row The document.
	 *
	 * @return array<string, mixed> The integrity to check against.
	 */
	private function integrityOf(array $row): array {
		$integrity = ($row['integrity'] ?? null);
		if (is_array($integrity) === true && (string)($integrity['value'] ?? '') !== '') {
			return $integrity;
		}

		return $this->firstHashOf(fileId: (int)($row['fileId'] ?? 0));
	}//end integrityOf()

	/**
	 * Write the outcome of a check onto the document, stamped with when it was made.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store The document store.
	 * @param array{id: string, integrity: array<string, mixed>}               $check The document's id and its checked integrity.
	 *
	 * @return void
	 */
	private function recordCheck(array $store, array $check): void {
		$check['integrity']['checkedAt'] = (new DateTimeImmutable())->format(format: 'Y-m-d\TH:i:s');
		$this->patchObjectAsArray(
			objectService: $store['objectService'],
			register: $store['register'],
			schema: $store['schema'],
			id: $check['id'],
			changes: ['integrity' => $check['integrity']],
		);
	}//end recordCheck()

	/**
	 * The hash of a document's file as it is now, empty when there is no file or it is too large to hash.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return array<string, mixed> The integrity block.
	 */
	private function firstHashOf(int $fileId): array {
		$node = null;
		if ($fileId > 0) {
			$node = $this->rootFolder->getFirstNodeById(id: $fileId);
		}

		if ($node instanceof File === false) {
			return [];
		}

		$integrity = $this->defaults->integrityOf(node: $node);
		if ((string)($integrity['value'] ?? '') === '') {
			return [];
		}

		return $integrity;
	}//end firstHashOf()

	/**
	 * The outcome of hashing the file now against the stored hash.
	 *
	 * @param string $stored The stored hash.
	 * @param int    $fileId The file id.
	 *
	 * @return string `unchanged`, `changed`, `missing`, or `unchecked` when the file is too large to hash.
	 */
	private function statusOf(string $stored, int $fileId): string {
		$node = null;
		if ($fileId > 0) {
			$node = $this->rootFolder->getFirstNodeById(id: $fileId);
		}

		if ($node instanceof File === false) {
			return 'missing';
		}

		$current = (string)($this->defaults->integrityOf(node: $node)['value'] ?? '');
		if ($current === '') {
			return 'unchecked';
		}

		if (hash_equals(known_string: strtolower($stored), user_string: $current) === true) {
			return 'unchanged';
		}

		return 'changed';
	}//end statusOf()
}//end class
