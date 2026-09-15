/**
 * What a case type asks for before a case of it exists, read on the client.
 *
 * Pure functions over the declaration `GET /api/intake/case-types/{id}/requirements`
 * answers with. No axios, no component: the create form and the assignee picker
 * both ask the same questions, and the answers have to be the same or the
 * picker will offer something the write refuses.
 *
 * 🔴 NONE OF THIS IS THE ENFORCEMENT. The write is refused by
 * `IntakeRequirementsListener` on the server, on the pre-persist event, and it
 * stays refused for an integration that never loads this file. What these
 * functions buy is that a handler is asked for the field before the save rather
 * than after it, and that the picker does not list a team the write will turn
 * down.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

/**
 * The four classification facets, in the order a form asks for them.
 *
 * Mirrors `CaseClassification::FACETS`.
 */
export const CLASSIFICATION_FACETS = [
	'classification',
	'sensitivity',
	'actionFacet',
	'insightLevel',
]

/**
 * The confidentiality levels the case schema carries.
 *
 * The VNG vertrouwelijkheidaanduiding, same list as `case.confidentiality`.
 */
export const CONFIDENTIALITY_VALUES = [
	'openbaar',
	'beperkt_openbaar',
	'intern',
	'zaakvertrouwelijk',
	'vertrouwelijk',
	'confidentieel',
	'geheim',
	'zeer_geheim',
]

/**
 * A declaration with every key present, whatever the server sent.
 *
 * A half-read answer must not make the form think a case type asks for
 * nothing, so every caller normalises first and nobody writes `?.` chains.
 *
 * @param {object} declaration The answer from the requirements endpoint.
 * @return {object} The same declaration with every key filled in.
 */
export function normaliseDeclaration(declaration) {
	const source = declaration || {}
	const requirements = source.intakeRequirements || {}
	const classification = source.classification || {}
	const narrowing = source.assigneeNarrowing || {}

	return {
		caseType: source.caseType || '',
		requiredBeforeCreation: [...(requirements.requiredBeforeCreation || [])],
		requiredBeforeComplete: [...(requirements.requiredBeforeComplete || [])],
		scheme: classification.scheme || '',
		classificationIsAccessRule:
			classification.classificationIsAccessRule === true,
		facets: [...(classification.facets || [])],
		classificationValues: [...(source.classificationValues || [])],
		schemeResolves: source.schemeResolves !== false,
		allowedGroups: [...(narrowing.allowedGroups || [])],
		allowedUsers: [...(narrowing.allowedUsers || [])],
		narrowsNothing: source.narrowsNothing !== false,
		refusalDestination: source.refusalDestination || {
			department: '',
			role: '',
		},
		canRefuse: source.canRefuse === true,
		unreadable: source.unreadable === true,
	}
}

/**
 * The fields a create form has to ask for before it may save.
 *
 * The declared before-creation list, plus the classification when the case
 * type made it the access rule. The classification is added even when it is
 * absent from the list, for the same reason the server adds it: leaving it off
 * while marking it the access rule is a contradiction, and the half that
 * decides who can reach the case wins.
 *
 * @param {object} declaration The answer from the requirements endpoint.
 * @return {string[]} The field names, without repeats.
 */
export function fieldsToAsk(declaration) {
	const read = normaliseDeclaration(declaration)
	const fields = [...read.requiredBeforeCreation]

	if (read.classificationIsAccessRule && !fields.includes('classification')) {
		fields.push('classification')
	}

	return fields.filter((field, index) => fields.indexOf(field) === index)
}

/**
 * Whether this case type asks for anything before the case exists.
 *
 * @param {object} declaration The answer from the requirements endpoint.
 * @return {boolean} True when a form has to ask something first.
 */
export function asksAnything(declaration) {
	return fieldsToAsk(declaration).length > 0
}

/**
 * The values one field may carry, or an empty list when it is free text.
 *
 * @param {string} field       The case field.
 * @param {object} declaration The answer from the requirements endpoint.
 * @return {string[]} The allowed values, empty when anything goes.
 */
export function optionsFor(field, declaration) {
	const read = normaliseDeclaration(declaration)

	if (field === 'confidentiality') {
		return [...CONFIDENTIALITY_VALUES]
	}

	if (field === 'classification') {
		return [...read.classificationValues]
	}

	if (field === 'assignedGroup') {
		return [...read.allowedGroups]
	}

	if (field === 'assignee') {
		return [...read.allowedUsers]
	}

	return []
}

/**
 * The fields this case would still be refused for.
 *
 * Mirrors `IntakeRequirements::missingBeforeCreation()`: a blank string, an
 * empty list and a missing key are unanswered, while `false` and `0` are
 * answers. Reading them the same way would let a case through on a field
 * nobody filled in.
 *
 * @param {object} values      The case as it would be written.
 * @param {object} declaration The answer from the requirements endpoint.
 * @return {string[]} The unanswered field names, in declared order.
 */
export function missingFrom(values, declaration) {
	const answers = values || {}

	return fieldsToAsk(declaration).filter((field) => {
		const value = answers[field]

		if (value === undefined || value === null) {
			return true
		}
		if (typeof value === 'string') {
			return value.trim() === ''
		}
		if (Array.isArray(value)) {
			return value.length === 0
		}

		return false
	})
}

/**
 * The options a picker may offer, narrowed by what the case type allows.
 *
 * An empty allowed list keeps every option, because a case type that narrowed
 * nothing has not said anything about who may take the case. Options may be
 * plain strings or objects, so the id is read through `identify`.
 *
 * @param {Array}    offered  The options the picker would show.
 * @param {string[]} allowed  The references the case type allows.
 * @param {(option: string|object) => string} identify Reads the reference off one option.
 * @return {Array} The options that survive, in the offered order.
 */
export function narrowOptions(offered, allowed, identify = (option) => option) {
	const options = offered || []
	const list = allowed || []

	if (list.length === 0) {
		return [...options]
	}

	return options.filter((option) => list.includes(identify(option)))
}

/**
 * The teams a picker may offer for a case of this type.
 *
 * @param {Array}    offered     The teams the picker would show.
 * @param {object}   declaration The answer from the requirements endpoint.
 * @param {(option: string|object) => string} identify Reads the reference off one option.
 * @return {Array} The teams that survive the narrowing.
 */
export function narrowGroups(offered, declaration, identify) {
	return narrowOptions(
		offered,
		normaliseDeclaration(declaration).allowedGroups,
		identify,
	)
}

/**
 * The people a picker may offer for a case of this type.
 *
 * @param {Array}    offered     The people the picker would show.
 * @param {object}   declaration The answer from the requirements endpoint.
 * @param {(option: string|object) => string} identify Reads the reference off one option.
 * @return {Array} The people that survive the narrowing.
 */
export function narrowUsers(offered, declaration, identify) {
	return narrowOptions(
		offered,
		normaliseDeclaration(declaration).allowedUsers,
		identify,
	)
}

/**
 * The sentence a refused write carries, out of the error envelope.
 *
 * ADR-050 puts the prose in `message` and the rule slug in `error`. A caller
 * that shows `error` puts a kebab-case slug in front of a handler, and one that
 * shows the axios message shows "Request failed with status code 422".
 *
 * @param {object} error The axios error, or the response body.
 * @return {string} One sentence, or '' when the body carries none.
 */
export function refusalSentence(error) {
	// 🔴 THE ENVELOPE ONLY, NEVER THE ERROR ITSELF. Falling back to `error`
	// read `Error.message` as though it were the refusal, so a dropped
	// connection put "Network Error" on screen where the rule's sentence
	// belongs. A caller that gets '' shows its own fallback and is right.
	const body = envelopeOf(error)

	return typeof body.message === 'string' ? body.message : ''
}

/**
 * The rule slug a refused write names.
 *
 * @param {object} error The axios error, or the response body.
 * @return {string} The rule, or '' when the body carries none.
 */
export function refusalRule(error) {
	const body = envelopeOf(error)

	return typeof body.error === 'string' ? body.error : ''
}

/**
 * The `{message, error}` body a refusal carries, out of whatever it arrived in.
 *
 * An axios error nests it under `response.data`; a caller that already unpacked
 * the response hands the body straight in. An `Error` carries neither and reads
 * as an empty envelope.
 *
 * @param {object} error The axios error, or the response body.
 * @return {object} The envelope, or an empty object.
 */
function envelopeOf(error) {
	if (!error || typeof error !== 'object') {
		return {}
	}
	if (error.response?.data && typeof error.response.data === 'object') {
		return error.response.data
	}
	if (error.data && typeof error.data === 'object') {
		return error.data
	}
	if (error instanceof Error) {
		return {}
	}

	return error
}
