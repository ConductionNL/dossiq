// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A label that is a plain string or a language map
 * (`{"nl": "In behandeling nemen", "en": "Take on"}`), as a transition's label is.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

import { getUserLocale, resolveTranslatable } from './i18nResolver.js'

/**
 * The label in the reader's language.
 *
 * @param {string|object|null|undefined} value The label.
 * @return {string} The text, '' when there is none.
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
export function labelText(value) {
	return resolveTranslatable(value).text
}

/**
 * The label with the text for one language replaced: a language map keeps its
 * other languages, a plain string becomes the new text.
 *
 * @param {string|object|null|undefined} value The label.
 * @param {string} text The new text.
 * @param {string} [locale] The language the text is in; the reader's by default.
 * @return {string|object} The new label.
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
export function withLabelText(value, text, locale = getUserLocale()) {
	if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
		return { ...value, [locale]: text }
	}
	return text
}
