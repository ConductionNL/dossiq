<?php

/**
 * Dossiq file document lookup: which document record names a file
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
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Read regardless of the caller: OpenRegister asks about a restricted file on behalf of somebody who may not see the
 * record. Only the id leaves this class.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 */
class FileDocumentLookup {
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
	 * The id of the document record that names a file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return string The record uuid, '' when no record names the file or OpenRegister is not configured.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
	 */
	public function recordIdOfFile(int $fileId): string {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($fileId <= 0 || $objectService === null || $register === '' || $schema === '') {
			return '';
		}

		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['fileId' => $fileId, '_limit' => 1],
		);
		$row = (array)($rows[0] ?? []);
		$self = (array)($row['@self'] ?? []);

		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end recordIdOfFile()
}//end class
