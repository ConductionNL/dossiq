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
	 * @return int How many documents with a stored hash were checked.
	 *
	 * @spec openspec/specs/document-integrity-check/spec.md#requirement-stored-checksums-are-checked-again-and-the-check-is-recorded
	 */
	public function verifyNext(int $batch = 200): int {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return 0;
		}

		$offset = $this->appConfig->getValueInt(Application::APP_ID, self::CURSOR_KEY, 0);
		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_limit' => $batch, '_offset' => $offset],
		);

		$next = ($offset + count($rows));
		if (count($rows) < $batch) {
			$next = 0;
		}

		$this->appConfig->setValueInt(Application::APP_ID, self::CURSOR_KEY, $next);

		$checked = 0;
		foreach ($rows as $row) {
			if ($this->verifyOne(objectService: $objectService, register: $register, schema: $schema, row: $row) === true) {
				$checked++;
			}
		}

		return $checked;
	}//end verifyNext()

	/**
	 * Check one document; a document without a stored hash is left alone.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The document schema.
	 * @param array<string, mixed> $row           The document.
	 *
	 * @return bool Whether the document was checked.
	 */
	private function verifyOne(object $objectService, string $register, string $schema, array $row): bool {
		$integrity = $row['integrity'] ?? null;
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if (is_array($integrity) === false || (string)($integrity['value'] ?? '') === '' || $id === '') {
			return false;
		}

		$status = $this->statusOf(stored: (string)$integrity['value'], fileId: (int)($row['fileId'] ?? 0));
		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $id,
				changes: [
					'integrity' => [
						...$integrity,
						'status' => $status,
						'checkedAt' => (new DateTimeImmutable())->format(format: 'Y-m-d\TH:i:s'),
					],
				],
			);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end verifyOne()

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
