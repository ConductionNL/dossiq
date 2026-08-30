<?php

/**
 * Dossiq Field Validator
 *
 * Stateless utility for ZGW field-format validation: UUID extraction,
 * syntactic URL validation, and ISO-8601 date validation. Extracted from
 * ZgwRulesBase so that the format-checking primitives can be reused across
 * the per-register rule services without inheriting the full base class.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-decomp-036
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Stateless field-format validator.
 *
 * Provides pure (side-effect-free) checks for the data shapes that recur
 * throughout the ZGW rule services: UUIDs, resource URLs and dates. Because
 * every method is deterministic and stateless, this class is trivially
 * unit-testable and can be shared by every register-specific rules service.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-decomp-036
 */
class FieldValidator {
	/**
	 * Regular expression matching a bare RFC-4122 UUID.
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Regular expression matching a UUID embedded anywhere in a string.
	 *
	 * @var string
	 */
	private const UUID_SUBSTRING_PATTERN = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

	/**
	 * Regular expression matching a URL path that ends in a UUID segment.
	 *
	 * @var string
	 */
	private const URL_UUID_TAIL_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i';

	/**
	 * Extract a UUID from a URL or plain UUID string.
	 *
	 * If the input is already a bare UUID it is returned verbatim; otherwise
	 * the first embedded UUID substring is returned, or null when none exists.
	 *
	 * @param string $url The URL or UUID
	 *
	 * @return string|null The extracted UUID, or null
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-decomp-036
	 */
	public function extractUuid(string $url): ?string {
		if (preg_match(self::UUID_PATTERN, $url) === 1) {
			return $url;
		}

		if (preg_match(self::UUID_SUBSTRING_PATTERN, $url, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}//end extractUuid()

	/**
	 * Check if a value is a bare RFC-4122 UUID.
	 *
	 * @param string $value The candidate UUID
	 *
	 * @return bool True when the value is exactly a UUID
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-decomp-036
	 */
	public function isUuid(string $value): bool {
		return preg_match(self::UUID_PATTERN, $value) === 1;
	}//end isUuid()

	/**
	 * Check if a URL is a syntactically valid ZGW resource URL.
	 *
	 * A ZGW resource URL must be a valid absolute URL whose path ends with a
	 * UUID segment (collection endpoints and URLs with trailing garbage are
	 * rejected).
	 *
	 * @param string $url The URL to check
	 *
	 * @return bool True if valid
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-decomp-036
	 */
	public function isValidUrl(string $url): bool {
		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$path = (string)parse_url($url, PHP_URL_PATH);

		return preg_match(self::URL_UUID_TAIL_PATTERN, $path) === 1;
	}//end isValidUrl()

	/**
	 * Check if a value is a valid ISO-8601 calendar date (YYYY-MM-DD).
	 *
	 * Validates both the format and that the date components describe a real
	 * calendar day (e.g. 2026-02-30 is rejected).
	 *
	 * @param string $value The candidate date string
	 *
	 * @return bool True when the value is a real YYYY-MM-DD date
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-decomp-036
	 */
	public function isValidDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
			return false;
		}

		return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
	}//end isValidDate()
}//end class
