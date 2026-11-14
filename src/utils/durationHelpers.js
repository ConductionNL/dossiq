/**
 * ISO 8601 duration helpers for parsing, formatting, and validating durations.
 *
 * Supports: P{n}Y, P{n}M, P{n}W, P{n}D, and combinations like P1Y6M.
 */
import { translate as t } from '@nextcloud/l10n'

const DURATION_REGEX = /^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?$/

/**
 * Check whether a string is a valid ISO 8601 duration.
 *
 * @param {string} value The string to validate
 * @return {boolean}
 * @spec openspec/specs/milestone-tracking/spec.md
 */
export function isValidDuration(value) {
	if (!value || typeof value !== 'string') return false
	return DURATION_REGEX.test(value) && value !== 'P'
}

/**
 * Parse an ISO 8601 duration into its components.
 *
 * @param {string} iso ISO 8601 duration string (e.g., "P56D", "P2M", "P1Y6M")
 * @return {{ years: number, months: number, weeks: number, days: number } | null}
 * @spec openspec/specs/milestone-tracking/spec.md
 */
export function parseDuration(iso) {
	if (!isValidDuration(iso)) return null
	const match = iso.match(DURATION_REGEX)
	return {
		years: parseInt(match[1] || '0', 10),
		months: parseInt(match[2] || '0', 10),
		weeks: parseInt(match[3] || '0', 10),
		days: parseInt(match[4] || '0', 10),
	}
}

// The units of a duration, largest first, each with its one and many form
// as literal t() calls so the string extraction reads them.
const DURATION_UNITS = [
	{ key: 'years', one: () => t('dossiq', '1 year'), many: (n) => t('dossiq', '{n} years', { n }) },
	{ key: 'months', one: () => t('dossiq', '1 month'), many: (n) => t('dossiq', '{n} months', { n }) },
	{ key: 'weeks', one: () => t('dossiq', '1 week'), many: (n) => t('dossiq', '{n} weeks', { n }) },
	{ key: 'days', one: () => t('dossiq', '1 day'), many: (n) => t('dossiq', '{n} days', { n }) },
]

/**
 * Format an ISO 8601 duration as a human-readable string.
 *
 * @param {string} iso ISO 8601 duration string
 * @return {string} Human-readable text (e.g., "56 days", "2 months", "1 year, 6 months")
 * @spec openspec/specs/milestone-tracking/spec.md
 */
export function formatDuration(iso) {
	const parsed = parseDuration(iso)
	if (!parsed) return iso || ''

	const parts = DURATION_UNITS
		.map((unit) => unitWords(unit, parsed[unit.key]))
		.filter((words) => words !== null)

	return parts.length > 0 ? parts.join(', ') : iso
}

/**
 * One unit of a duration in words: its one form, its many form, or null
 * for none of it.
 *
 * @param {{key: string, one: () => string, many: (n: number) => string}} unit The unit.
 * @param {number} count How many of it.
 * @return {string|null} The words.
 */
function unitWords(unit, count) {
	if (count === 1) {
		return unit.one()
	}
	return count > 1 ? unit.many(count) : null
}

/**
 * Get a validation error message for a duration field, or empty string if valid.
 *
 * @param {string} value The value to validate
 * @return {string} Error message or empty string
 * @spec openspec/specs/milestone-tracking/spec.md
 */
export function getDurationError(value) {
	if (!value) return ''
	if (!isValidDuration(value)) {
		return t(
			'dossiq',
			'Must be a valid ISO 8601 duration (e.g., P56D for 56 days, P8W for 8 weeks, P2M for 2 months)',
		)
	}
	return ''
}
