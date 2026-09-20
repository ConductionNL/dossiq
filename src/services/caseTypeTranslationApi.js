/**
 * OpenRegister translation client for a case type's labels.
 *
 * The engine is OpenRegister's (ADR-022). dossiq declares which properties are
 * labels and shows an administrator what is still missing; it stores nothing of
 * its own and computes no translation.
 *
 *   GET   /apps/openregister/api/registers/{register}
 *         — the languages this register serves. `languages[0]` is the source.
 *   GET   /apps/openregister/api/objects/{register}/{schema}/{id}
 *           ?_translations=all&_translationMeta=true
 *         — every language of every value, plus `_meta.languageMeta`, which
 *           names exactly the properties OpenRegister holds as translatable.
 *   GET   /apps/openregister/api/translations/object/{uuid}?schema={schema}
 *         — the sidecar rows, each with the workflow status of one value.
 *   PATCH /apps/openregister/api/objects/{register}/{schema}/{id}
 *         — the edited label, as a whole language-keyed object.
 *
 * 🔑 THE WHOLE LANGUAGE MAP IS WRITTEN, never one language under
 * `X-Translation-Target-Language`. With that header
 * `TranslationHandler::normalizeTranslationsForSave()` builds a FRESH
 * single-key map (`[$targetLanguage => $value]`), so whether the Dutch value
 * survives depends on merge behaviour further down that this client cannot
 * see. A body that is already language keyed is kept verbatim and needs no
 * header, which is the same edit with nothing left to find out.
 *
 * 🔑 THE PROPERTIES COME FROM `_meta.languageMeta`, not from a list kept here.
 * The envelope is built from the schema OpenRegister imported, so a label this
 * app declared but OpenRegister never read is absent, and the page says so by
 * not offering it. A list in the browser would offer a field that saves into
 * nothing.
 *
 * Nothing here turns a failure into an empty answer. An outage and a case type
 * with no translations look the same from the browser, and only one of them is
 * somebody's problem, so a failed read throws and the widget draws an error.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const API = '/apps/openregister/api'

/** The register and schema a case type lives in. */
export const REGISTER = 'dossiq'

/** The schema a case type lives in. */
export const SCHEMA = 'caseType'

/**
 * A translation row is stale when the source value moved under it.
 *
 * OpenRegister flips a derived row to `outdated` when the source-language value
 * changes (`SaveObject::flagOutdatedDerivedTranslations()`). A stale label is a
 * wrong label, so it does not count as translated.
 *
 * @type {string}
 */
export const STATUS_OUTDATED = 'outdated'

/**
 * The languages the register serves, source language first.
 *
 * @return {Promise<string[]>} The declared chain, Dutch first on a default install.
 * @throws {Error} When the register cannot be read.
 *
 * @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
 */
export async function registerLanguages() {
	const { data } = await axios.get(generateUrl(`${API}/registers/${REGISTER}`))

	const languages = data?.languages ?? data?.register?.languages ?? []

	return Array.isArray(languages)
		? languages.filter((code) => typeof code === 'string' && code !== '')
		: []
}

/**
 * One case type with every language of every value, and its label envelope.
 *
 * @param {string} id The case type id.
 * @return {Promise<{object: object, languageMeta: object}>} The values and the envelope.
 * @throws {Error} When the case type cannot be read.
 *
 * @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
 */
export async function caseTypeWithTranslations(id) {
	const { data } = await axios.get(
		generateUrl(
			`${API}/objects/${REGISTER}/${SCHEMA}/${encodeURIComponent(id)}`,
		),
		{ params: { _translations: 'all', _translationMeta: 'true' } },
	)

	const object = data?.object ?? data ?? {}

	return { object, languageMeta: object?._meta?.languageMeta ?? {} }
}

/**
 * The `caseType` schema's own property definitions, for their titles.
 *
 * 🔑 `_meta.languageMeta` CARRIES NO TITLE. Its entries are `served`,
 * `sourceLanguage`, `isSource` and `status` (openregister
 * `RenderObject::attachLanguageMeta()`), so a label read off the envelope
 * would silently fall back to the property name on every field. The titles
 * live on the schema, which is where this reads them.
 *
 * @return {Promise<object>} Property name to its definition.
 * @throws {Error} When the register's schemas cannot be read.
 *
 * @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
 */
export async function caseTypeProperties() {
	const { data } = await axios.get(
		generateUrl(`${API}/registers/${REGISTER}/schemas`),
	)

	const schemas = data?.results ?? data?.schemas ?? data ?? []
	const list = Array.isArray(schemas) ? schemas : Object.values(schemas)
	const schema = list.find((entry) => (entry?.slug ?? entry?.title) === SCHEMA)

	return schema?.properties ?? {}
}

/**
 * The sidecar rows for one case type, so a stale value can be told apart.
 *
 * @param {string} uuid The case type uuid.
 * @return {Promise<Array<object>>} The rows, each naming property, language and status.
 * @throws {Error} When the sidecar cannot be read.
 *
 * @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
 */
export async function translationRows(uuid) {
	const { data } = await axios.get(
		generateUrl(`${API}/translations/object/${encodeURIComponent(uuid)}`),
		{ params: { schema: SCHEMA } },
	)

	const rows = data?.translations ?? []

	return Array.isArray(rows) ? rows : []
}

/**
 * Write one property back with every language it now holds.
 *
 * @param {string} id The case type id.
 * @param {string} property The label being edited.
 * @param {object} languageMap Every language of that label, the edit included.
 * @return {Promise<object>} The saved object as OpenRegister returns it.
 * @throws {Error} When the write is refused.
 *
 * @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
 */
export async function saveLabel(id, property, languageMap) {
	const { data } = await axios.patch(
		generateUrl(
			`${API}/objects/${REGISTER}/${SCHEMA}/${encodeURIComponent(id)}`,
		),
		{ [property]: languageMap },
	)

	return data?.object ?? data ?? {}
}

/**
 * How many of a language's labels are present and current.
 *
 * 🔴 THIS IS NOT OpenRegister's `completeness`, deliberately.
 * `TranslationMapper::getCompletenessByObject()` counts every row with a
 * non-empty value and never looks at its status, so a translation the source
 * moved out from under still counts as done. REQ-CFI-04 says a stale label is a
 * wrong label, not a present one, and this is where that is applied.
 *
 * The source language is always complete by definition: its values ARE the
 * source, and a source row carries no status to go stale.
 *
 * @param {object} args The inputs.
 * @param {string[]} args.properties The translatable labels.
 * @param {object} args.values Property to language to value.
 * @param {object} args.statuses Property to language to workflow status.
 * @param {string} args.language The language being counted.
 * @param {string} args.sourceLanguage The language the labels are written in.
 * @return {{translated: number, total: number}} The counts behind the chip.
 *
 * @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
 */
export function completenessOf({
	properties,
	values,
	statuses,
	language,
	sourceLanguage,
}) {
	const total = properties.length

	const translated = properties.filter((property) => {
		const value = values?.[property]?.[language] ?? ''
		if (typeof value !== 'string' || value.trim() === '') {
			return false
		}

		if (language === sourceLanguage) {
			return true
		}

		return (statuses?.[property]?.[language] ?? '') !== STATUS_OUTDATED
	}).length

	return { translated, total }
}
