/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every schema a case points at must be able to name its own rows.
 *
 * OpenRegister denormalises a display string into `@self.name` when it saves a
 * row. It takes that string from `configuration.objectNameField`, and when the
 * schema declares none it tries the property names `naam`, `name`, `title`,
 * `label`, `titel` in turn. A schema that offers neither leaves `@self.name`
 * unset, and `ObjectEntity` then answers the uuid instead.
 *
 * That is invisible until something renders the row by name, and a `$ref` on
 * `case` is exactly that: the value becomes a picker option, a facet bucket
 * label and a column, all keyed by identifier and labelled by name. `case`'s
 * `assignedGroup` points at `organisatieRol`, which declared no name field, so
 * the Team picker listed uuids and the Team facet bucket read `3c26f5c4…`
 * rather than `Team Permits`. Only the Team COLUMN was right, because it
 * extends the reference and reads `roleName` itself.
 *
 * The rule is asserted over every `$ref` target on `case` rather than over
 * that one schema, because a schema with no name field is the kind of thing
 * that gets copied: six of the seven targets already satisfy it, so the guard
 * costs nothing today and catches the next relation added to a case.
 *
 * Read from disk, and merged the way `SettingsService::loadConfiguration()`
 * merges it (base, then `register.d/*.json` in sorted filename order), so the
 * assertion covers the configuration OpenRegister actually imports rather than
 * the base file alone. `organisatieRol` is declared in a fragment, so reading
 * only the base would have missed it entirely.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const SETTINGS_DIR = path.resolve(__dirname, '../../lib/Settings')
const BASE_PATH = path.join(SETTINGS_DIR, 'dossiq_register.json')
const FRAGMENT_DIR = path.join(SETTINGS_DIR, 'register.d')

/**
 * The property names OpenRegister falls back to when a schema configures none.
 *
 * Mirrors `MetadataHydrationHandler::hydrateMetadata()`'s `tryCommonFields`
 * list, which is the wider of the two OpenRegister uses (`SchemaMapper`'s
 * auto-population omits `label`). The wider list is the right one to assert
 * against: it is what decides at save time whether a name is written.
 */
const FALLBACK_NAME_PROPERTIES = ['naam', 'name', 'title', 'label', 'titel']

const loadJson = (filePath) => JSON.parse(fs.readFileSync(filePath, 'utf8'))

/**
 * Whether a value is a mergeable associative object.
 *
 * @param {*} value The value.
 * @return {boolean} True for a plain object.
 */
function isPlainObject(value) {
	return typeof value === 'object' && value !== null && Array.isArray(value) === false
}

/**
 * Deep-merge a fragment onto a base, matching the loader's semantics: objects
 * merge key by key, arrays concatenate, scalars overwrite.
 *
 * @param {*} base     The base value.
 * @param {*} fragment The fragment value.
 * @return {*} The merged value.
 */
function deepMerge(base, fragment) {
	if (Array.isArray(base) && Array.isArray(fragment)) return [...base, ...fragment]
	if (isPlainObject(base) === false || isPlainObject(fragment) === false) return fragment

	const merged = { ...base }
	for (const [key, value] of Object.entries(fragment)) {
		merged[key] = Object.hasOwn(base, key) ? deepMerge(base[key], value) : value
	}
	return merged
}

/**
 * The schema map OpenRegister imports.
 *
 * @return {object} `{ slug: schema }`.
 */
function loadSchemas() {
	let configuration = loadJson(BASE_PATH)
	const fragments = fs
		.readdirSync(FRAGMENT_DIR)
		.filter((entry) => entry.endsWith('.json'))
		.sort()

	for (const fragment of fragments) {
		configuration = deepMerge(configuration, loadJson(path.join(FRAGMENT_DIR, fragment)))
	}

	return configuration.components.schemas
}

/**
 * Every schema slug a property tree points at with `$ref`.
 *
 * @param {*} node The node to walk.
 * @return {string[]} The slugs, in encounter order.
 */
function refTargets(node) {
	if (Array.isArray(node)) return node.flatMap(refTargets)
	if (isPlainObject(node) === false) return []

	return Object.entries(node).flatMap(([key, value]) =>
		key === '$ref' && typeof value === 'string'
			? [value.split('/').pop()]
			: refTargets(value),
	)
}

/**
 * How a schema resolves its rows' display name, or null when it cannot.
 *
 * @param {object} schema The schema.
 * @return {string|null} The configured or fallback property, else null.
 */
function nameSource(schema) {
	const configured = (schema.configuration || {}).objectNameField
	if (typeof configured === 'string' && configured.trim() !== '') return configured

	const properties = Object.keys(schema.properties || {})
	return FALLBACK_NAME_PROPERTIES.find((candidate) => properties.includes(candidate)) ?? null
}

describe('a schema a case points at can name its own rows', () => {
	const schemas = loadSchemas()
	const targets = [...new Set(refTargets(schemas.case.properties))].filter(
		(slug) => slug !== 'case',
	)

	it('finds the relation targets to check', () => {
		expect(targets.length).toBeGreaterThan(0)
		expect(targets).toContain('organisatieRol')
	})

	it.each(targets)('%s resolves a display name', (slug) => {
		expect(schemas[slug], `case points at "${slug}", which no fragment declares`).toBeDefined()
		expect(
			nameSource(schemas[slug]),
			`"${slug}" declares no configuration.objectNameField and no property named one of `
				+ `${FALLBACK_NAME_PROPERTIES.join(', ')}, so OpenRegister names every row by its uuid`,
		).not.toBeNull()
	})
})

describe('organisatieRol names its rows by the role name', () => {
	it('points objectNameField at roleName, the property the Team column already reads', () => {
		const schemas = loadSchemas()
		expect(schemas.organisatieRol.configuration.objectNameField).toBe('roleName')
	})

	it('keeps roleName required, so the name can never be empty', () => {
		const schemas = loadSchemas()
		expect(schemas.organisatieRol.required).toContain('roleName')
	})
})
