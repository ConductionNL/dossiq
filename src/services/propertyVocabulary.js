/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The property vocabulary the case-type editor authors against.
 *
 * OpenRegister publishes what a property may be at
 * `/apps/openregister/api/schemas/property-vocabulary`: every type its
 * validator accepts, the constraint keys each type takes, the formats it
 * supports and the keys a form may forward. dossiq reads that list rather
 * than keeping one of its own, because a second list is a list that drifts.
 * The editor offered eight types while the engine validated nineteen, and
 * nobody could see the gap from either side.
 *
 * An instance whose OpenRegister does not answer the endpoint yet falls back
 * to `VOCABULARY_SNAPSHOT` and says so on screen. The snapshot offers less
 * than the instance accepts, never more, so the fallback cannot invent a type
 * the engine would refuse.
 *
 * @see openspec/changes/casetype-field-vocabulary/design.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { VOCABULARY_SNAPSHOT } from './propertyVocabularySnapshot.js'

/**
 * Where OpenRegister publishes the vocabulary.
 *
 * @type {string}
 */
export const VOCABULARY_URL = generateUrl(
	'/apps/openregister/api/schemas/property-vocabulary',
)

/**
 * The eight types dossiq offered before this vocabulary reached the editor.
 *
 * Five of them were never types the engine validates: `date`, `url`, `email`
 * and `enum` are a string with a format or a constraint, and `json` is an
 * object. They stay accepted so a stored definition keeps working and keeps
 * its value, and each one names what replaces it. Nothing rewrites them.
 *
 * @type {Object<string, {type: string, format: string, constraint: string}>}
 */
export const LEGACY_TYPE_ALIASES = {
	date: { type: 'string', format: 'date', constraint: '' },
	url: { type: 'string', format: 'url', constraint: '' },
	email: { type: 'string', format: 'email', constraint: '' },
	enum: { type: 'string', format: '', constraint: 'enum' },
	json: { type: 'object', format: '', constraint: '' },
}

/**
 * Keys a case type wants to declare that OpenRegister has not published yet.
 *
 * `x-openregister-property-source` is the key integriq's
 * `registry-backed-field-source` asked OpenRegister for: a field whose values
 * come from BAG, BRP or KvK. OpenRegister owns it and has not shipped it, so
 * the extends-form map cannot forward it: a form may only forward a key the
 * vocabulary holds, and `ExtendingFormDeclaration` refuses the rest by name.
 *
 * The definition carries the source regardless, so a case type authored today
 * keeps the administrator's answer. `propertyDefinitionSchema.spec.js` fails
 * the moment the vocabulary does hold the key, which is the one reminder to
 * move it into the map.
 *
 * @type {Object<string, {key: string, owner: string, reason: string}>}
 */
export const PENDING_PLATFORM_KEYS = {
	propertySource: {
		key: 'x-openregister-property-source',
		owner: 'openregister',
		reason: 'Asked for by integriq registry-backed-field-source, not published yet.',
	},
}

/**
 * Map roles the case form renderer reads that the vocabulary does not name.
 *
 * `propertiesFromDefinitions` in `@conduction/nextcloud-vue` reads the
 * extends-form map as role to definition field, and its roles are the
 * vocabulary's keys with one addition: `definition` is the helper text it
 * falls back to when the description is empty. That role is the renderer's,
 * not OpenRegister's, so the contract test allows it by name and by reason
 * rather than allowing anything that is not a vocabulary key.
 *
 * @type {Object<string, string>}
 */
export const RENDERER_ROLES = {
	definition:
		'The helper text @conduction/nextcloud-vue falls back to when the description is empty.',
}

/**
 * What the editor says when it is reading the snapshot instead of the instance.
 *
 * @type {string}
 */
export const FALLBACK_NOTICE = 'openregister_vocabulary_unavailable'

let pending = null
let cached = null

/**
 * Read a vocabulary answer that is shaped like one.
 *
 * A Nextcloud route that does not exist answers the page shell with a 200, so
 * a truthy response proves nothing. The types array is what makes it a
 * vocabulary.
 *
 * @param {object} data The response body.
 * @return {boolean} True when this is a vocabulary and not a page.
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
export function isVocabulary(data) {
	return (
		!!data
		&& Array.isArray(data.types)
		&& data.types.length > 0
		&& Array.isArray(data.keys)
		&& typeof data.types[0].type === 'string'
	)
}

/**
 * The vocabulary this instance authors against.
 *
 * @param {object} [options] Options.
 * @param {boolean} [options.force] Read again rather than answer from cache.
 * @return {Promise<{vocabulary: object, source: string, notice: string}>}
 *   The vocabulary, where it came from, and the notice to show when it is the
 *   snapshot.
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
export async function fetchPropertyVocabulary(options = {}) {
	if (options.force !== true && cached !== null) {
		return cached
	}
	if (options.force !== true && pending !== null) {
		return pending
	}
	pending = (async () => {
		let answer = {
			vocabulary: VOCABULARY_SNAPSHOT,
			source: 'snapshot',
			notice: FALLBACK_NOTICE,
		}
		try {
			const response = await axios.get(VOCABULARY_URL)
			if (isVocabulary(response?.data)) {
				answer = {
					vocabulary: response.data,
					source: 'instance',
					notice: '',
				}
			}
		} catch {
			// An OpenRegister without the endpoint answers 404, and one that is
			// not installed answers nothing. Both mean the same here: author
			// against the snapshot and say so.
		}
		cached = answer
		pending = null
		return answer
	})()
	return pending
}

/**
 * Forget the cached vocabulary.
 *
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
export function resetPropertyVocabulary() {
	cached = null
	pending = null
}

/**
 * The type names the vocabulary holds.
 *
 * @param {object} vocabulary The vocabulary.
 * @return {string[]} The type names, in the order the engine declares them.
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
export function typeNames(vocabulary) {
	return (vocabulary?.types || []).map((row) => row.type)
}

/**
 * Every key a form may forward, including the vendor extensions.
 *
 * @param {object} vocabulary The vocabulary.
 * @return {string[]} The keys.
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
export function vocabularyKeys(vocabulary) {
	return vocabulary?.keys || []
}

/**
 * The formats a type supports.
 *
 * @param {object} vocabulary The vocabulary.
 * @param {string} type The type name.
 * @return {string[]} The formats, empty for every type but string.
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
export function formatsForType(vocabulary, type) {
	const row = (vocabulary?.types || []).find((entry) => entry.type === type)
	return row?.formats || []
}

/**
 * The constraint keys a type takes.
 *
 * @param {object} vocabulary The vocabulary.
 * @param {string} type The type name.
 * @return {string[]} The constraint keys.
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
export function constraintsForType(vocabulary, type) {
	const row = (vocabulary?.types || []).find((entry) => entry.type === type)
	return row?.constraints || []
}

/**
 * What the editor knows about a stored type.
 *
 * Three answers, and the difference between them is what an administrator is
 * allowed to do next. A type the vocabulary holds is edited. A type dossiq
 * used to offer is edited too, and named as old. A type from neither list was
 * authored on an instance that knows more than this one: it is shown, it is
 * not touched, and it is never coerced to text.
 *
 * @param {object} vocabulary The vocabulary.
 * @param {string} stored The stored propertyType.
 * @return {{known: boolean, legacy: boolean, type: string, replacement: object}}
 *   What this type is and what replaces it.
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
export function resolveStoredType(vocabulary, stored) {
	const type = stored || 'string'
	if (typeNames(vocabulary).includes(type)) {
		return { known: true, legacy: false, type, replacement: null }
	}
	if (Object.hasOwn(LEGACY_TYPE_ALIASES, type)) {
		return {
			known: true,
			legacy: true,
			type,
			replacement: LEGACY_TYPE_ALIASES[type],
		}
	}
	return { known: false, legacy: false, type, replacement: null }
}

/**
 * The types the editor offers for one definition.
 *
 * Only the vocabulary's own types are offered for a new field. A definition
 * that already carries one of the old dossiq types keeps that one in its own
 * list, so opening it does not silently retype it, and so the administrator
 * can see what to move to.
 *
 * @param {object} vocabulary The vocabulary.
 * @param {string} stored The type this definition carries, if any.
 * @return {Array<{type: string, category: string, legacy: boolean, description: string}>}
 *   The options, in vocabulary order, with the stored legacy type last.
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
export function typeOptionsFor(vocabulary, stored) {
	const options = (vocabulary?.types || []).map((row) => ({
		type: row.type,
		category: row.category,
		legacy: false,
		description: row.description || '',
	}))
	const resolved = resolveStoredType(vocabulary, stored)
	if (resolved.legacy === true) {
		options.push({
			type: resolved.type,
			category: 'legacy',
			legacy: true,
			description: '',
		})
	}
	return options
}
