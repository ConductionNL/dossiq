<?php

/**
 * The purpose and role rules of the access-purpose dialog, from app config.
 *
 * Which generic roles open a sensitive case without the dialog, which purpose
 * is recorded for them, and which purpose binds the reader to which generic
 * role. Configuration, not procedure code: the app config key
 * `access_purpose_roles` overrides any part of the defaults.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/case-access-purpose/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\People\CaseRoleHolder;

/**
 * Reads the access-purpose rules.
 *
 * @spec openspec/specs/case-access-purpose/spec.md
 */
class AccessPurposeRules {

	/**
	 * The app configuration key of the rules.
	 */
	public const KEY = 'access_purpose_roles';

	/**
	 * The rules when the configuration names none: the case's handler opens
	 * it without the dialog, as handling, and handling makes the reader the
	 * handler. Generic roles are role types' `genericRole`.
	 */
	public const DEFAULTS = [
		'skipFor' => [CaseRoleHolder::GENERIC_HANDLER],
		'skipPurpose' => 'handling',
		'assign' => ['handling' => CaseRoleHolder::GENERIC_HANDLER],
	];

	/**
	 * The rules: the configured parts over the defaults.
	 *
	 * @param string             $raw      The configured JSON, '' for none.
	 * @param array<int, string> $purposes The purposes a reader can pick.
	 *
	 * @return array{skipFor: array<int, string>, skipPurpose: string, assign: array<string, string>} The rules.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md
	 */
	public static function fromConfig(string $raw, array $purposes): array {
		$own = json_decode($raw, true);
		if (is_array($own) === false) {
			return self::DEFAULTS;
		}

		return [
			'skipFor' => self::strings(value: ($own['skipFor'] ?? null), fallback: self::DEFAULTS['skipFor']),
			'skipPurpose' => self::purpose(value: ($own['skipPurpose'] ?? null), purposes: $purposes),
			'assign' => self::assignments(value: ($own['assign'] ?? null)),
		];
	}//end fromConfig()

	/**
	 * A list of strings, or the fallback when the value is not a list.
	 *
	 * @param mixed              $value    The configured value.
	 * @param array<int, string> $fallback The default.
	 *
	 * @return array<int, string> The strings.
	 */
	private static function strings(mixed $value, array $fallback): array {
		if (is_array($value) === false) {
			return $fallback;
		}

		return array_values(array_filter($value, 'is_string'));
	}//end strings()

	/**
	 * A purpose the reader could pick, or the default one.
	 *
	 * @param mixed              $value    The configured value.
	 * @param array<int, string> $purposes The purposes a reader can pick.
	 *
	 * @return string The purpose.
	 */
	private static function purpose(mixed $value, array $purposes): string {
		if (is_string($value) === true && in_array($value, $purposes, true) === true) {
			return $value;
		}

		return self::DEFAULTS['skipPurpose'];
	}//end purpose()

	/**
	 * The purpose to generic role map, or the default one.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return array<string, string> The map.
	 */
	private static function assignments(mixed $value): array {
		if (is_array($value) === false) {
			return self::DEFAULTS['assign'];
		}

		return array_filter($value, static fn (mixed $role): bool => is_string($role) === true && $role !== '');
	}//end assignments()
}//end class
