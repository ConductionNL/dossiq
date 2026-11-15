<?php

/**
 * Dossiq case type schema.
 *
 * Tells whether an object payload belongs to the configured `caseType`
 * schema, by its schema id or by a schema reference ending in it.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\SchemaMembership;

/**
 * Whether a payload is a case type.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class CaseTypeSchema {

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Holds the configured case type schema.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
	) {
	}//end __construct()

	/**
	 * Whether the supplied payload belongs to the `caseType` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a case type.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SchemaMembership::belongsTo() is a pure predicate on the payload.
	 */
	public function contains(array $object): bool {
		return SchemaMembership::belongsTo(object: $object, expected: $this->settingsService->getConfigValue('case_type_schema'));
	}//end contains()
}//end class
