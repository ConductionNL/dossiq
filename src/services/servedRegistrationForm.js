/**
 * The registration form buildiq serves for a case type, and what a create writes from it.
 *
 * buildiq answers the app-local leaf `buildiq-registration-form` on the case
 * type object (OpenRegister's integration route); dossiq asks, buildiq never
 * writes the case. The desk asks as the signed-in handler with the
 * `employee` audience and the `balie` channel; the channel's own forms come
 * first, so `items[0]` is the one this create files under.
 *
 * A channel is a value of the case's own `intakeChannel` enum: buildiq checks
 * a form's channel against the property its `channelProperty` names, so the
 * desk is `balie` here, not the word `desk`. A served channel outside that
 * enum (a form that names no channel property is not checked) is not
 * written, because OpenRegister would refuse the whole case for it.
 *
 * 🔴 OPTIONAL BY DESIGN. Without buildiq, with no form for the case type, or
 * with a read that failed, the answer is null and the case is saved exactly
 * as the manifest form built it. A gemeente must be able to open cases
 * whether or not a form builder is installed or reachable.
 *
 * Hidden presets travel only in `presets[]` (buildiq removes them from
 * `fields[]` so they never reach the page) and are written here, on save.
 * They fill what the handler left empty and never replace an answer the
 * handler gave in the visible form: a value someone typed is the better
 * statement of fact than a default an administrator set for everyone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/competitor-parity-2026-09/tasks.md#15
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The leaf id, equal on both halves so the leaf gate can pair them. */
export const REGISTRATION_FORM_LEAF = 'buildiq-registration-form'

/** The case field the served form's channel is written to. */
export const FIELD_INTAKE_CHANNEL = 'intakeChannel'

/**
 * The values `case.intakeChannel` accepts, as lib/Settings/dossiq_register.json
 * declares them (a vitest keeps the two in step).
 */
export const INTAKE_CHANNELS = Object.freeze(['manual', 'balie', 'phone', 'email', 'post', 'website', 'forms', 'other', 'zgw-api'])

/**
 * The schema default of `intakeChannel`: what a form holds when nobody chose,
 * so a served channel may replace it.
 */
export const DEFAULT_INTAKE_CHANNEL = 'manual'

/** Who asks from the desk, and on which channel. */
export const DESK_ASK = Object.freeze({ audience: 'employee', channel: 'balie' })

/**
 * The form buildiq serves for a case type on a channel.
 *
 * @param {string} caseTypeId The chosen case type's id.
 * @param {{audience?: string, channel?: string}} [ask] Who asks, on which channel.
 * @return {Promise<object|null>} The served form (`items[0]`), or null when nothing answers.
 *
 * @spec openspec/changes/competitor-parity-2026-09/tasks.md#15
 */
export async function fetchServedRegistrationForm(caseTypeId, ask = DESK_ASK) {
	if (typeof caseTypeId !== 'string' || caseTypeId === '') {
		return null
	}
	const params = {}
	for (const key of ['audience', 'channel']) {
		if (typeof ask?.[key] === 'string' && ask[key] !== '') {
			params[key] = ask[key]
		}
	}
	try {
		const { data } = await axios.get(
			generateUrl(`/apps/openregister/api/objects/dossiq/caseType/${encodeURIComponent(caseTypeId)}/integrations/${REGISTRATION_FORM_LEAF}`),
			{ params },
		)
		const first = Array.isArray(data?.items) ? data.items[0] : null
		return first && typeof first === 'object' ? first : null
	} catch {
		// Not installed (unknown leaf), no access or unreachable: the
		// manifest form stands, as it does on an instance without buildiq.
		return null
	}
}

/**
 * Whether a candidate field holds an answer.
 *
 * @param {unknown} value The field's value.
 * @return {boolean} True when the handler filled it.
 */
function isAnswered(value) {
	if (value === undefined || value === null) {
		return false
	}
	if (typeof value === 'string') {
		return value.trim() !== ''
	}
	return !(Array.isArray(value) && value.length === 0)
}

/**
 * The candidate with what the served form writes on save.
 *
 * Writes each hidden preset where the handler left the field empty, and the
 * form's channel to `intakeChannel` where the handler left it empty or at its
 * schema default. A null form returns the candidate unchanged.
 *
 * @param {object} candidate The case to save.
 * @param {object|null} form The served form, or null.
 * @return {object} The case to save.
 *
 * @spec openspec/changes/competitor-parity-2026-09/tasks.md#15
 */
export function withServedForm(candidate, form) {
	if (!form || typeof form !== 'object') {
		return candidate
	}
	const written = { ...candidate }
	for (const preset of Array.isArray(form.presets) ? form.presets : []) {
		const key = typeof preset?.key === 'string' ? preset.key : ''
		if (key !== '' && isAnswered(preset.value) && !isAnswered(written[key]) && fits(key, preset.value)) {
			written[key] = preset.value
		}
	}
	const channel = written[FIELD_INTAKE_CHANNEL]
	if (fits(FIELD_INTAKE_CHANNEL, form.channel) && (!isAnswered(channel) || channel === DEFAULT_INTAKE_CHANNEL)) {
		written[FIELD_INTAKE_CHANNEL] = form.channel
	}
	return written
}

/**
 * Whether a value may be written to a case field: `intakeChannel` takes only
 * its enum, every other field is left to the server's own validation.
 *
 * @param {string} key The case field.
 * @param {unknown} value The value to write.
 * @return {boolean} True when the value may be written.
 */
function fits(key, value) {
	if (key !== FIELD_INTAKE_CHANNEL) {
		return true
	}
	return typeof value === 'string' && INTAKE_CHANNELS.includes(value)
}
