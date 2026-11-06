<?php

/**
 * Dossiq document case ids
 *
 * The cases a document is joined to, read for an authorization check. The
 * read runs regardless of the caller, because a case the caller cannot see is
 * exactly the one that must refuse, so only the ids leave this class.
 *
 * Kept apart from DocumentRecordStore, whose reads are the caller's: this is
 * the one unscoped read in the document projection, and it answers an access
 * question, not a projection one.
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
 * @spec openspec/specs/document-writes-need-every-case/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Reads the ids of the cases a document belongs to, whoever asks.
 *
 * @spec openspec/specs/document-writes-need-every-case/spec.md
 */
class DocumentCaseIds {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The ids of every case a document is joined to, read regardless of the
	 * caller: an authorization check must also see a case the caller cannot.
	 * Only the ids leave this method.
	 *
	 * @param string $recordId The document uuid.
	 *
	 * @return list<string> The case ids.
	 *
	 * @spec openspec/specs/document-writes-need-every-case/spec.md#requirement-a-document-write-needs-the-change-right-on-every-case-of-the-document
	 */
	public function caseIdsOf(string $recordId): array {
		[$objectService, $register] = $this->requireRegister();
		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $this->schema(key: 'dossier_zaakinformatieobject_schema'),
			filters: ['informatieobject' => $recordId, '_limit' => 500],
		);

		$ids = [];
		foreach ($rows as $row) {
			$caseId = $row['case'] ?? '';
			if (is_array($caseId) === true) {
				$caseId = $caseId['id'] ?? '';
			}

			$caseId = (string)$caseId;
			if ($caseId !== '') {
				$ids[$caseId] = true;
			}
		}

		return array_keys($ids);
	}//end caseIdsOf()

	/**
	 * The ids of every document joined to a case, read regardless of the caller: a handler change must reach the
	 * documents the new handler cannot read yet. Only the ids leave this method.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return list<string> The document ids.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
	 */
	public function documentIdsOf(string $caseId): array {
		[$objectService, $register] = $this->requireRegister();
		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $this->schema(key: 'dossier_zaakinformatieobject_schema'),
			filters: ['case' => $caseId, '_limit' => 500],
		);

		$ids = [];
		foreach ($rows as $row) {
			$documentId = $row['informatieobject'] ?? '';
			if (is_array($documentId) === true) {
				$documentId = $documentId['id'] ?? '';
			}

			$documentId = (string)$documentId;
			if ($documentId !== '' && in_array($documentId, $ids, true) === false) {
				$ids[] = $documentId;
			}
		}

		return $ids;
	}//end documentIdsOf()

	/**
	 * The object service and register, or an exception.
	 *
	 * @return array{0: object, 1: string} The object service and the register.
	 *
	 * @throws RuntimeException When OpenRegister or the register is not configured.
	 */
	private function requireRegister(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end requireRegister()

	/**
	 * A configured schema slug or id, or an exception when unset.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema.
	 *
	 * @throws RuntimeException When the key is not configured.
	 */
	private function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossier schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
