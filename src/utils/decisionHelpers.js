/**
 * Decision helper utilities for validity calculations and display.
 */

/**
 * Get the validity status of a decision.
 *
 * @param {object} decision Decision object with effectiveDate and expiryDate
 * @return {{ status: string, label: string, style: string, remaining: string|null }}
 * @spec openspec/specs/roles-decisions/spec.md
 */
export function getDecisionValidity(decision) {
	const today = startOfDay(new Date())

	if (decision.effectiveDate && startOfDay(new Date(decision.effectiveDate)) > today) {
		return notYetEffective(decision)
	}

	if (decision.expiryDate) {
		return expiryValidity(decision, today)
	}

	// No expiry date — indefinitely valid
	if (decision.effectiveDate) {
		return activeFrom(decision)
	}

	return unknownValidity()
}

/**
 * A date set to the start of its day, in place.
 *
 * @param {Date} date The date.
 * @return {Date} The same date, at midnight.
 */
function startOfDay(date) {
	date.setHours(0, 0, 0, 0)
	return date
}

/**
 * The validity of a decision with an expiry date that is already effective.
 *
 * @param {object} decision The decision.
 * @param {Date} today Today, at midnight.
 * @return {{ status: string, label: string, style: string, remaining: string|null }}
 */
function expiryValidity(decision, today) {
	const expiry = startOfDay(new Date(decision.expiryDate))

	if (expiry < today) {
		return expired()
	}

	const diffMs = expiry - today
	const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

	if (diffDays <= 30) {
		return expiringSoon(decision, diffDays)
	}

	return activeUntil(decision)
}

/**
 * A decision without dates.
 *
 * @return {{ status: string, label: string, style: string, remaining: null }}
 */
function unknownValidity() {
	return {
		status: 'unknown',
		label: '',
		style: '',
		remaining: null,
	}
}

/**
 * A decision past its expiry date.
 *
 * @return {{ status: string, label: string, style: string, remaining: null }}
 */
function expired() {
	return {
		status: 'expired',
		label: t('dossiq', 'Expired'),
		style: 'validity--expired',
		remaining: null,
	}
}

/**
 * A decision whose effective date lies ahead.
 *
 * @param {object} decision The decision.
 * @return {{ status: string, label: string, style: string, remaining: string }}
 */
function notYetEffective(decision) {
	return {
		status: 'not_effective',
		label: t('dossiq', 'Not yet effective'),
		style: 'validity--pending',
		remaining: t('dossiq', 'Effective from {date}', {
			date: formatDecisionDate(decision.effectiveDate),
		}),
	}
}

/**
 * A decision that expires within 30 days.
 *
 * @param {object} decision The decision.
 * @param {number} diffDays The days until it expires.
 * @return {{ status: string, label: string, style: string, remaining: string }}
 */
function expiringSoon(decision, diffDays) {
	return {
		status: 'expiring_soon',
		label: t('dossiq', 'Expires in {days} days', { days: diffDays }),
		style: 'validity--warning',
		remaining: t('dossiq', 'Expires {date}', {
			date: formatDecisionDate(decision.expiryDate),
		}),
	}
}

/**
 * A decision valid until a later expiry date.
 *
 * @param {object} decision The decision.
 * @return {{ status: string, label: string, style: string, remaining: string }}
 */
function activeUntil(decision) {
	return {
		status: 'active',
		label: t('dossiq', 'Active'),
		style: 'validity--active',
		remaining: t('dossiq', 'Valid until {date}', {
			date: formatDecisionDate(decision.expiryDate),
		}),
	}
}

/**
 * A decision valid from its effective date, without an end.
 *
 * @param {object} decision The decision.
 * @return {{ status: string, label: string, style: string, remaining: string }}
 */
function activeFrom(decision) {
	return {
		status: 'active',
		label: t('dossiq', 'Active'),
		style: 'validity--active',
		remaining: t('dossiq', 'From {date}', {
			date: formatDecisionDate(decision.effectiveDate),
		}),
	}
}

/**
 * Format a date string for decision display.
 *
 * @param {string} dateString ISO date string
 * @return {string}
 * @spec openspec/specs/roles-decisions/spec.md
 */
export function formatDecisionDate(dateString) {
	if (!dateString) return '—'
	const date = new Date(dateString)
	return date.toLocaleDateString(undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	})
}

/**
 * Validate a decision form.
 *
 * @param {object} form Decision form data
 * @return {{ valid: boolean, errors: object }}
 * @spec openspec/specs/roles-decisions/spec.md
 */
export function validateDecision(form) {
	const errors = {}

	if (!form.title || !form.title.trim()) {
		errors.title = t('dossiq', 'Title is required')
	}

	if (form.effectiveDate && form.expiryDate) {
		const effective = new Date(form.effectiveDate)
		const expiry = new Date(form.expiryDate)
		if (expiry <= effective) {
			errors.expiryDate = t(
				'dossiq',
				'Expiry date must be after effective date',
			)
		}
	}

	return {
		valid: Object.keys(errors).length === 0,
		errors,
	}
}
