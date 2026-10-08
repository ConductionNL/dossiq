<?php

/**
 * Whether a register and schema reference another app hands back names a dossiq case.
 *
 * Another app's event says which object it was about the way OpenRegister
 * serialised it: `@self.register` and `@self.schema`, usually the numeric ids,
 * sometimes a slug, sometimes a path ending in one. dossiq's own configuration
 * holds the register and the case schema as whatever the import stored. So the
 * comparison accepts the configured value itself, a path that ends in it, and
 * the shipped slugs (`dossiq` / `case`); the slug only counts when the register
 * is dossiq's too, because `case` is not a name only dossiq uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;

/**
 * Recognises a reference to a dossiq case.
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class CaseObjectReference {

	/**
	 * The case schema's shipped slug.
	 *
	 * @var string
	 */
	private const CASE_SLUG = 'case';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The configured register and case schema.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Whether the reference names a dossiq case.
	 *
	 * @param string|null $register The register, as the other app gave it.
	 * @param string|null $schema   The schema, as the other app gave it.
	 *
	 * @return bool True when it is a case in dossiq's register.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function isCase(?string $register, ?string $schema): bool {
		$register = strtolower(trim((string)$register));
		$schema = strtolower(trim((string)$schema));
		if ($register === '' || $schema === '') {
			return false;
		}

		$ownRegister = strtolower($this->settingsService->getConfigValue(key: 'register'));
		$ownSchema = strtolower($this->settingsService->getConfigValue(key: 'case_schema'));

		$registerMatches = ($this->same(candidate: $register, configured: $ownRegister) === true || $register === Application::APP_ID);
		if ($registerMatches === false) {
			return false;
		}

		return $this->same(candidate: $schema, configured: $ownSchema) === true || $schema === self::CASE_SLUG;
	}//end isCase()

	/**
	 * Whether a reference equals a configured value, or is a path ending in it.
	 *
	 * @param string $candidate  The reference, lower-cased.
	 * @param string $configured The configured value, lower-cased.
	 *
	 * @return bool True when they name the same thing.
	 */
	private function same(string $candidate, string $configured): bool {
		if ($configured === '') {
			return false;
		}

		return $candidate === $configured || str_ends_with($candidate, '/' . $configured);
	}//end same()
}//end class
