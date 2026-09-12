import { translate as t } from '@nextcloud/l10n'
import { isValidDuration } from './durationHelpers.js'

export const REQUIRED_FIELDS = [
	'title',
	'purpose',
	'trigger',
	'subject',
	'processingDeadline',
	'origin',
	'confidentiality',
	'responsibleUnit',
]

/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
export function getOriginOptions() {
	return [
		{ id: 'internal', label: t('dossiq', 'Internal') },
		{ id: 'external', label: t('dossiq', 'External') },
	]
}

/**
 * The confidentiality levels a case type can carry.
 *
 * The ids are the caseType schema's ZGW `vertrouwelijkheidaanduiding` enum,
 * which OpenRegister enforces. They used to be English (`public`, `internal`,
 * `secret`), so every save of a level from the General tab was refused and a
 * stored level never showed as selected. The labels stay English and
 * translatable; only the stored value changed.
 *
 * @return {Array<{id: string, label: string}>} The options, lowest level first.
 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
 */
export function getConfidentialityOptions() {
	return [
		{ id: 'openbaar', label: t('dossiq', 'Public') },
		{ id: 'beperkt_openbaar', label: t('dossiq', 'Restricted') },
		{ id: 'intern', label: t('dossiq', 'Internal') },
		{ id: 'zaakvertrouwelijk', label: t('dossiq', 'Case sensitive') },
		{ id: 'vertrouwelijk', label: t('dossiq', 'Confidential') },
		{ id: 'confidentieel', label: t('dossiq', 'Highly confidential') },
		{ id: 'geheim', label: t('dossiq', 'Secret') },
		{ id: 'zeer_geheim', label: t('dossiq', 'Top secret') },
	]
}

/**
 * Validate a case type object. Returns per-field errors.
 *
 * @param {object} data Case type data
 * @return {{ valid: boolean, errors: object }}
 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
 */
export function validateCaseType(data) {
	const errors = {}

	for (const field of REQUIRED_FIELDS) {
		if (
			!data[field]
			|| (typeof data[field] === 'string' && !data[field].trim())
		) {
			errors[field] = t('dossiq', '{field} is required', {
				field: getFieldLabel(field),
			})
		}
	}

	if (data.processingDeadline && !isValidDuration(data.processingDeadline)) {
		errors.processingDeadline = t(
			'dossiq',
			'Must be a valid ISO 8601 duration (e.g., P56D)',
		)
	}

	if (data.serviceTarget && !isValidDuration(data.serviceTarget)) {
		errors.serviceTarget = t(
			'dossiq',
			'Must be a valid ISO 8601 duration (e.g., P42D)',
		)
	}

	if (
		data.extensionAllowed
		&& (!data.extensionPeriod || !data.extensionPeriod.trim())
	) {
		errors.extensionPeriod = t(
			'dossiq',
			'Extension period is required when extension is allowed',
		)
	}

	if (data.extensionPeriod && !isValidDuration(data.extensionPeriod)) {
		errors.extensionPeriod = t(
			'dossiq',
			'Must be a valid ISO 8601 duration (e.g., P28D)',
		)
	}

	if (data.validFrom && data.validUntil && data.validUntil <= data.validFrom) {
		errors.validUntil = t('dossiq', "'Valid until' must be after 'Valid from'")
	}

	return {
		valid: Object.keys(errors).length === 0,
		errors,
	}
}

// The browser-side publish validation used to live here as
// `validateForPublish`. It is gone rather than unused: the server's
// `CaseTypePublishService::validate()` is the answer the Publish gesture now
// shows, and it is a DIFFERENT answer on a case type that inherits its
// lifecycle from a parent, where this one reported "at least one status type
// must be defined" on a page showing four statuses. Two validators for one
// gesture is how a page refuses what the server would have accepted.

/**
 *
 * @param {object} field The field.
 */
function getFieldLabel(field) {
	const labels = {
		title: t('dossiq', 'Title'),
		purpose: t('dossiq', 'Purpose'),
		trigger: t('dossiq', 'Trigger'),
		subject: t('dossiq', 'Subject'),
		processingDeadline: t('dossiq', 'Processing deadline'),
		origin: t('dossiq', 'Origin'),
		confidentiality: t('dossiq', 'Confidentiality'),
		responsibleUnit: t('dossiq', 'Responsible unit'),
		extensionPeriod: t('dossiq', 'Extension period'),
		serviceTarget: t('dossiq', 'Service target'),
		validUntil: t('dossiq', 'Valid until'),
	}
	return labels[field] || field
}
