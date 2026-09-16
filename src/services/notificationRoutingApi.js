/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The notification preferences, read and written where they live.
 *
 * These call OpenRegister directly rather than a dossiq endpoint that forwards
 * (ADR-022). Two reasons, and the second is the load-bearing one. A pass-through
 * controller is dead code the moment the platform's shape changes. And setting a
 * team's default is an act of administration over that team: the check that the
 * writer administers it lives beside OpenRegister's own write, so a second
 * endpoint in front of it could only weaken it.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE = '/apps/openregister/api'

/**
 * The schemas whose notifications dossiq owns.
 *
 * The platform answers for every app on the instance. A dossiq settings screen
 * that listed opencatalogi's notifications would be offering to change
 * somebody else's app from here.
 *
 * @type {Array<string>}
 */
export const DOSSIQ_SCHEMAS = ['case', 'substitution', 'workDigest', 'queueMention']

/**
 * The notification domains a preference may be pinned to.
 *
 * Exactly the domains the shipped rules declare, so a pin always has a rule to
 * match. Kept beside NotificationRouting::DOMAINS and swept against the rules
 * by tests/Unit/Settings/NotificationRoutingFragmentTest.php.
 *
 * @type {Array<string>}
 */
export const DOMAINS = ['zaken', 'waarneming', 'werkvoorraad']

/**
 * Every notification this person has, with the layer that decided each.
 *
 * @param {?string} scope Pin the answer to one scope, e.g. `domain:zaken`.
 * @return {Promise<Array<object>>} The entries, dossiq's own only.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 */
export async function fetchPreferences(scope = null) {
	const params = (scope ? { scope } : {})
	const { data } = await axios.get(generateUrl(`${BASE}/notification-preferences`), { params })
	const results = (Array.isArray(data?.results) ? data.results : [])

	return results.filter((entry) => DOSSIQ_SCHEMAS.includes(entry?.schema))
}

/**
 * Record this person's own value for one notification.
 *
 * @param {object} preference What to record.
 * @param {string} preference.schema The schema the rule lives on.
 * @param {string} preference.notification The rule's own key.
 * @param {boolean} preference.enabled Whether they want it.
 * @param {?string} preference.scope The scope to pin it to, or null for global.
 * @return {Promise<object>} What the platform stored.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 */
export async function savePreference({ schema, notification, enabled, scope = null }) {
	const body = { schema, notification, enabled }
	if (scope) {
		body.scope = scope
	}

	const { data } = await axios.put(generateUrl(`${BASE}/notification-preferences`), body)

	return data
}

/**
 * Clear this person's own value, so the layer below decides again.
 *
 * @param {object} preference Which one to clear.
 * @param {string} preference.schema The schema the rule lives on.
 * @param {string} preference.notification The rule's own key.
 * @param {?string} preference.scope The scope it was pinned to.
 * @return {Promise<object>} What the platform stored.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 */
export async function clearPreference({ schema, notification, scope = null }) {
	const body = { schema, notification, reset: true }
	if (scope) {
		body.scope = scope
	}

	const { data } = await axios.put(generateUrl(`${BASE}/notification-preferences`), body)

	return data
}

/**
 * Set a team's default for one notification.
 *
 * The platform refuses this with a 403 unless the writer administers the group,
 * so the caller shows the refusal rather than deciding for itself who may.
 *
 * @param {object} preference What to record.
 * @param {string} preference.group The group.
 * @param {string} preference.schema The schema the rule lives on.
 * @param {string} preference.notification The rule's own key.
 * @param {boolean} preference.enabled Whether the team gets it by default.
 * @param {?string} preference.scope The scope to pin it to.
 * @return {Promise<object>} What the platform stored.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 */
export async function saveGroupDefault({ group, schema, notification, enabled, scope = null }) {
	const body = { group, schema, notification, enabled }
	if (scope) {
		body.scope = scope
	}

	const { data } = await axios.put(generateUrl(`${BASE}/notification-group-preferences`), body)

	return data
}
