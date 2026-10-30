/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqMijnWerk "Termijnen deze week": each deadline reads "2026-4837 · Woo",
 * the case number and the case type's short name, read through the case
 * type (its title when it has no short name).
 *
 * @spec openspec/changes/case-type-short-name/specs/case-type-short-name/spec.md#requirement-a-case-type-can-carry-a-short-name
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const full = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/register.d/99-case-type-short-name.json'), 'utf8'))

/**
 * Find a widget by id anywhere in a page tree.
 *
 * @param {unknown} node The tree.
 * @param {string} id The widget id.
 * @return {object|undefined} The widget.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const found = findWidget(child, id)
			if (found) {
				return found
			}
		}
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
			return node
		}
		for (const child of Object.values(node)) {
			const found = findWidget(child, id)
			if (found) {
				return found
			}
		}
	}
	return undefined
}

/**
 * A shipped case type object by its slug.
 *
 * @param {string} file The register fragment.
 * @param {string} slug The object slug.
 * @return {object} The case type.
 */
function caseType(file, slug) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/register.d', file), 'utf8'))
		.components.objects.find((entry) => entry['@self'] && entry['@self'].slug === slug)
}

describe('the week strip names the case type', () => {
	const strip = findWidget(simple, 'simple-week')

	it('extends the case type and reads its short name, else its title', () => {
		expect(strip.content.source.extend).toEqual(['caseType'])
		expect(strip.content.metaFields).toEqual(['identifier', ['caseType.shortName', 'caseType.title']])
	})

	it('declares shortName on the case type as a short translatable text', () => {
		const schema = fragment.components.schemas.caseType
		expect(schema.version).toBe('1.46.0')
		expect(schema.properties.shortName).toMatchObject({ type: 'string', maxLength: 30, translatable: true, sourceLanguage: 'nl' })
	})

	it('gives both Woo request types the short name Woo', () => {
		expect(caseType('81-woo-verzoek.json', 'woo-verzoek').shortName).toBe('Woo')
		expect(caseType('81-woo-verzoek-version-2.json', 'woo-verzoek-v2').shortName).toBe('Woo')
	})

	it('lets an administrator set it on the case type, next to the title', () => {
		const list = full.pages.find((page) => page.id === 'CaseTypes').config
		expect(list.includeFields.slice(0, 2)).toEqual(['title', 'shortName'])
		expect(list.fieldOverrides.shortName).toEqual({ order: 1.5 })
		const core = findWidget(full.pages.find((page) => page.id === 'CaseTypeDetail'), 'case-type-core')
		expect(core.content.include.slice(0, 2)).toEqual(['title', 'shortName'])
	})
})
