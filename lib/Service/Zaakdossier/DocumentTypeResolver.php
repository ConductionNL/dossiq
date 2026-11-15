<?php

/**
 * Resolves a document type NAME to the catalogue row's id.
 *
 * `informatieobject.informatieobjecttype` holds a reference, and a template or
 * a draft declaration names its type the way a person would
 * ("Ontvangstbevestiging"). When the catalogue holds no such row the name is
 * passed through unchanged rather than blanked: the Documents tab renders an
 * unresolved type as its raw value, so the column still reads the name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * One lookup of a document type by name, for every road that files a document.
 *
 * @spec openspec/specs/template-library/spec.md
 */
class DocumentTypeResolver {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The catalogue row id of a document type name.
	 *
	 * @param string $name The type name.
	 *
	 * @return string The catalogue row id, or the name, or empty.
	 *
	 * @spec openspec/specs/template-library/spec.md
	 */
	public function resolve(string $name): string {
		if (trim($name) === '') {
			return '';
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'dossier_informatieobjecttype_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return $name;
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['description' => $name, '_limit' => 1],
		);

		$id = (string)(($rows[0]['id'] ?? ($rows[0]['uuid'] ?? '')));
		if ($id !== '') {
			return $id;
		}

		return $name;
	}//end resolve()
}//end class
