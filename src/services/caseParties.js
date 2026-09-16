/**
 * The parties on a case, in their roles, and what their indicators refuse.
 *
 * 🔴 EVERY LINE BELOW IS A READ, AND IT READS OPENREGISTER DIRECTLY. The party
 * model is OpenRegister's (openregister#3761) and ADR-022 says a leaf app
 * consumes the abstraction rather than wrapping it, the way `caseAccessApi.js`
 * already consumes the permission reads. A dossiq endpoint in front of these
 * would be a second place to keep in step with a contract dossiq does not own.
 *
 *   GET /apps/openregister/api/objects/{register}/{schema}/{id}/parties
 *       The links, grouped by role, with the kinds and roles the case schema
 *       declares and the uuid of the primary party. One read gives the widget
 *       its rows AND its vocabulary, so a role renders under its label rather
 *       than under its key.
 *
 *   GET /apps/openregister/api/parties/{partyUuid}
 *       One party's own record: its kind, its addresses and its indicators.
 *       The listing above carries links, and an indicator lives on the PARTY,
 *       so it is not on a link. This is asked once per party that has a uuid,
 *       which is the parties on the case and not the user or contact links
 *       written before the party model, so a case with no parties makes no
 *       extra call at all.
 *
 *   GET /apps/openregister/api/parties/resolve?address=
 *       The party already holding an inbound address. Asked BEFORE a second
 *       record is created for the same person.
 *
 * WHY A FAILED READ IS `null` AND NEVER AN EMPTY LISTING. A case with nobody
 * on it is a real answer and the widget says so in words. "We could not ask"
 * is a different answer, and rendering it as an empty party list would tell a
 * handler that the gemachtigde they added is gone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { CASE_REGISTER, CASE_SCHEMA } from './caseAccessApi.js'

/**
 * The generic party roles the case schema declares, with a label this app
 * translates itself.
 *
 * The schema stores ONE label per role, in the language the vocabulary sync
 * ran in, because OpenRegister normalises a `linkRoles` entry down to
 * `{key, label, description?}` and drops anything else. So the stored label
 * would show a Dutch reader an English word on an instance set up in English,
 * and the other way round. These six are translated again here, which is what
 * makes the reader's own language the one on screen. A role this map does not
 * know keeps the schema's label, which is what an instance's own role types
 * carry.
 *
 * @return {object} Role key to translated label.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export function genericRoleLabels() {
	return {
		aanvrager: t('dossiq', 'Requester'),
		gemachtigde: t('dossiq', 'Authorised representative'),
		belanghebbende: t('dossiq', 'Interested party'),
		afzender: t('dossiq', 'Sender'),
		geadresseerde: t('dossiq', 'Addressee'),
		locatie: t('dossiq', 'Location'),
	}
}

/**
 * The label for one role key.
 *
 * @param {string} key The role key, a generic party role or a role type uuid.
 * @param {Array<object>} roles The `roles` vocabulary the listing carried.
 * @return {string} The label, the key itself when nothing names it.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export function roleLabel(key, roles) {
	const generic = genericRoleLabels()[key]
	if (generic) {
		return generic
	}
	const declared = (roles || []).find((role) => role && role.key === key)
	if (declared && declared.label) {
		return declared.label
	}
	return key === 'other' ? t('dossiq', 'No role') : key
}

/**
 * The parties on a case, grouped by role.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object|null>} The listing, or null when it could not be read.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export async function fetchCaseParties(caseId) {
	if (!caseId) {
		return null
	}
	try {
		const { data } = await axios.get(
			generateUrl(
				`/apps/openregister/api/objects/${CASE_REGISTER}/${CASE_SCHEMA}/${encodeURIComponent(caseId)}/parties`,
			),
		)
		return data && typeof data === 'object' ? data : null
	} catch {
		return null
	}
}

/**
 * One party's own record: its kind, addresses and indicators.
 *
 * @param {string} partyUuid The party uuid.
 * @return {Promise<object|null>} The party, or null when it could not be read.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export async function fetchParty(partyUuid) {
	if (!partyUuid) {
		return null
	}
	try {
		const { data } = await axios.get(
			generateUrl(
				`/apps/openregister/api/parties/${encodeURIComponent(partyUuid)}`,
			),
		)
		return data && typeof data === 'object' ? data : null
	} catch {
		return null
	}
}

/**
 * The party already holding an inbound address, so a second record for the
 * same person is never created.
 *
 * A 404 is an answer, not a failure: nobody holds that address yet. Both come
 * back as null, because the caller does the same thing either way — it creates
 * the party it was going to create. What this call prevents is the case where
 * somebody DOES hold it.
 *
 * @param {string} address An email address, or any address a party may hold.
 * @return {Promise<object|null>} The party holding it, or null.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export async function resolvePartyByAddress(address) {
	const value = String(address || '').trim()
	if (value === '') {
		return null
	}
	try {
		const { data } = await axios.get(
			generateUrl('/apps/openregister/api/parties/resolve'),
			{ params: { address: value } },
		)
		if (!data || typeof data !== 'object') {
			return null
		}
		// The endpoint answers the party itself; an instance that answers a
		// wrapper is read through `party` rather than guessed at.
		const party = data.party || data
		return party && (party.id || party['@self']?.id || party.uuid)
			? party
			: null
	} catch {
		return null
	}
}

/**
 * The listing's roles in the order the widget renders them: the role the
 * primary party holds first, then the rest as the listing gave them.
 *
 * The primary party is the one the case is filed against, which on a dossiq
 * case is the initiator. A handler opening the People tab is looking for that
 * name before any other, so it is not left to fall wherever the grouping put
 * it.
 *
 * @param {object|null} listing The parties listing.
 * @return {Array<object>} `{key, label, parties}` per role, primary role first.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export function rolesInOrder(listing) {
	if (!listing || typeof listing !== 'object') {
		return []
	}
	const byRole = listing.byRole && typeof listing.byRole === 'object'
		? listing.byRole
		: {}
	const primary = listing.primary || null

	const groups = Object.keys(byRole).map((key) => ({
		key,
		label: roleLabel(key, listing.roles),
		parties: partiesInOrder(byRole[key], primary),
	}))

	const holdsPrimary = (group) =>
		!!primary && group.parties.some((party) => party.partyUuid === primary)

	return [
		...groups.filter(holdsPrimary),
		...groups.filter((group) => !holdsPrimary(group)),
	]
}

/**
 * One role's parties with the primary party first.
 *
 * @param {Array<object>} parties The links in that role.
 * @param {string|null} primary The primary party's uuid.
 * @return {Array<object>} The links, primary first.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export function partiesInOrder(parties, primary) {
	const rows = Array.isArray(parties) ? parties.filter(Boolean) : []
	if (!primary) {
		return rows
	}
	return [
		...rows.filter((row) => row.partyUuid === primary),
		...rows.filter((row) => row.partyUuid !== primary),
	]
}

/**
 * What one indicator does, as a sentence and a severity.
 *
 * An indicator with no effect, or an effect this version does not know, warns.
 * It never vanishes: a chip nobody can read is still a chip somebody asks
 * about, and a silently dropped one is not.
 *
 * @param {object} indicator The indicator, `{key, label, effect, note}`.
 * @return {object} `{effect, severity, verdict, label}`.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export function indicatorVerdict(indicator) {
	const effect = String(indicator?.effect || '').trim()
	const label = String(indicator?.label || indicator?.key || '').trim()

	if (effect === 'refuse-publication') {
		return {
			effect,
			severity: 'error',
			label,
			verdict: t('dossiq', 'Publishing a file on this case is refused'),
		}
	}
	if (effect === 'refuse-send') {
		return {
			effect,
			severity: 'error',
			label,
			verdict: t('dossiq', 'A message to this party is refused'),
		}
	}
	return {
		effect: effect === '' ? 'warn' : effect,
		severity: 'warning',
		label,
		verdict: t('dossiq', 'Read this before acting on the case'),
	}
}

/**
 * Every indicator carried by the parties of a case, each stamped with the
 * party that carries it.
 *
 * @param {Array<object>} parties The party records, as `fetchParty` answers them.
 * @return {Array<object>} `{party, partyName, key, label, effect}` entries.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export function indicatorsOf(parties) {
	const found = []
	for (const party of parties || []) {
		if (!party) {
			continue
		}
		const list = Array.isArray(party.indicators) ? party.indicators : []
		for (const indicator of list) {
			if (!indicator) {
				continue
			}
			found.push({
				...indicator,
				party: party.id || party.uuid || '',
				partyName: party.name || party.displayName || '',
			})
		}
	}
	return found
}

/**
 * Whether any indicator on the case refuses publication, and which.
 *
 * @param {Array<object>} indicators The indicators, as `indicatorsOf` answers them.
 * @return {object|null} The refusing indicator, or null.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
export function publicationRefusal(indicators) {
	return (
		(indicators || []).find(
			(indicator) => indicator && indicator.effect === 'refuse-publication',
		) || null
	)
}
