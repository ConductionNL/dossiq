<?php

/**
 * Field Validator Utility
 *
 * Provides reusable field validation methods for ZGW rules services.
 * Extracted from ZgwBusinessRulesService and ZgwZrcRulesService.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * Stateless field validation utility for ZGW rule services.
 *
 * All methods are side-effect-free and depend only on their arguments.
 * Instantiate once and inject wherever validation primitives are needed.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8
 */
class FieldValidator
{
    /**
     * Validate that a string value is a valid ISO 8601 date (YYYY-MM-DD).
     *
     * @param string $value The value to validate
     *
     * @return bool True when the value matches YYYY-MM-DD and is a real calendar date
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-8
     */
    public function validateDateFormat(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = explode('-', $value);

        return checkdate(month: (int) $month, day: (int) $day, year: (int) $year);
    }//end validateDateFormat()

    /**
     * Validate that a date range is chronologically ordered (from <= to).
     *
     * Both values must pass validateDateFormat() before this check is meaningful.
     *
     * @param string $from The start date string (YYYY-MM-DD)
     * @param string $to   The end date string (YYYY-MM-DD)
     *
     * @return bool True when $from is on or before $to
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-8
     */
    public function validateDateRange(string $from, string $to): bool
    {
        return $from <= $to;
    }//end validateDateRange()

    /**
     * Validate that a string is a syntactically valid URL.
     *
     * Uses PHP's FILTER_VALIDATE_URL — sufficient for ZGW endpoint checks.
     *
     * @param string $url The URL to validate
     *
     * @return bool True when the URL is syntactically valid
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-8
     */
    public function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }//end validateUrl()

    /**
     * Validate that a URL ends with a UUID path segment (resource endpoint).
     *
     * ZGW resource URLs must end with a valid UUID as the last path segment.
     * Collection endpoints (no UUID) and URLs with trailing garbage after the
     * UUID are rejected — mirrors the logic in ZgwRulesBase::isValidUrl().
     *
     * @param string $url The URL to validate
     *
     * @return bool True when the URL passes format validation and its last path segment is a UUID
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-8
     */
    public function validateUrlIsResourceEndpoint(string $url): bool
    {
        if ($this->validateUrl(url: $url) === false) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i',
            $path
        ) === 1;
    }//end validateUrlIsResourceEndpoint()
}//end class
