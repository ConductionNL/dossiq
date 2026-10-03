/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a field's choices come from, when two places could answer.
 *
 * A choice list used to be typed into the attribute that uses it. Two case
 * types that both ask for a reden each carried their own copy, and a
 * municipality-wide list of wijken or afhandelkanalen was retyped per type and
 * drifted. OpenRegister holds those lists as SKOS concept schemes, so a
 * property can point at one instead of carrying a copy.
 *
 * That leaves two sources on one definition, and the rule between them has to
 * live in one place or the form and the index will each guess. This module is
 * that place: the scheme rules when it is set, the inline list rules when it
 * is not, and a definition carrying both is an authoring mistake that is said
 * out loud rather than resolved in silence.
 *
 * dossiq declares the binding and does not render the picker. The picker is
 * the platform's, and the case form reads the binding once OpenRegister
 * publishes `x-openregister-concept-scheme` in its property vocabulary. Until
 * then the binding is stored and shown, and no value is coerced.
 *
 * @see openspec/changes/code-lists-from-concepts/design.md
 */

/**
 * The scheme is the source of options.
 *
 * @type {string}
 */
export const SOURCE_SCHEME = 'scheme'

/**
 * The inline `enumValues` list is the source of options.
 *
 * @type {string}
 */
export const SOURCE_INLINE = 'inline'

/**
 * The field takes any answer its type allows.
 *
 * @type {string}
 */
export const SOURCE_NONE = 'none'

/**
 * The scheme a definition is bound to, trimmed, or an empty string.
 *
 * A reference typed with a stray space is the same reference. An answer that
 * is not a string at all is no binding: it is read as absent rather than
 * stringified, because `[object Object]` is not a scheme anyone can resolve.
 *
 * @param {object} definition The property definition.
 * @return {string} The scheme reference, or an empty string.
 * @spec openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md
 */
export function schemeOf(definition) {
	const scheme = definition?.conceptScheme
	if (typeof scheme !== 'string') {
		return ''
	}
	return scheme.trim()
}

/**
 * The inline choices a definition carries.
 *
 * @param {object} definition The property definition.
 * @return {string[]} The values, empty when there are none.
 * @spec openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md
 */
export function inlineValuesOf(definition) {
	const values = definition?.enumValues
	if (!Array.isArray(values)) {
		return []
	}
	return values.filter((value) => typeof value === 'string' && value.trim())
}

/**
 * Whether both sources are set on one definition.
 *
 * @param {object} definition The property definition.
 * @return {boolean} True when a scheme and an inline list are both present.
 * @spec openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md
 */
export function hasCompetingSources(definition) {
	return schemeOf(definition) !== '' && inlineValuesOf(definition).length > 0
}

/**
 * Which source rules for one definition, and what it offers.
 *
 * One answer, read by the authoring form and the index alike, so the warning
 * and the rendering cannot disagree about which list a handler will see.
 *
 * @param {object} definition The property definition.
 * @return {{source: string, scheme: string, values: string[], competing: boolean}}
 *   The ruling source, the scheme it names, the inline values it would have
 *   used, and whether the definition carries both.
 * @spec openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md
 */
export function optionSourceFor(definition) {
	const scheme = schemeOf(definition)
	const values = inlineValuesOf(definition)
	const competing = scheme !== '' && values.length > 0
	if (scheme !== '') {
		return { source: SOURCE_SCHEME, scheme, values, competing }
	}
	if (values.length > 0) {
		return { source: SOURCE_INLINE, scheme: '', values, competing }
	}
	return { source: SOURCE_NONE, scheme: '', values: [], competing }
}
