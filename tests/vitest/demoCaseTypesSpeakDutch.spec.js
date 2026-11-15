/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shipped demo case types and their statuses carry a Dutch and an English
 * label for every translatable property.
 *
 * A scalar value in a seed is stored under the register's default language,
 * so an English title written as a plain string is stored as the Dutch one,
 * and a Dutch reader sees "Building Permit" on the case type, list and board
 * pages. A language-keyed value lets OpenRegister answer each reader in their
 * own language.
 *
 * OpenRegister only updates a stored seed whose `@self.version` is newer than
 * the version it last shipped, so each changed object carries a version above
 * the one it shipped before.
 *
 * @spec openspec/specs/case-configuration-i18n/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (rel) => JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf8'))

const seed = read('lib/Settings/register.d/46-demo-cases-english.json')
const schemas = read('lib/Settings/dossiq_register.json').components.schemas

function translatable(schema) {
	return Object.entries(schemas[schema].properties)
		.filter(([, def]) => def.translatable === true)
		.map(([key]) => key)
}

const objectsOf = (schema) => seed.components.objects.filter((o) => o['@self'].schema === schema)

function versionAbove(version, floor) {
	const a = String(version || '0.0.0').split('.').map(Number)
	const b = floor.split('.').map(Number)
	for (let i = 0; i < 3; i++) {
		if ((a[i] || 0) !== (b[i] || 0)) {
			return (a[i] || 0) > (b[i] || 0)
		}
	}
	return false
}

describe.each([
	['caseType', '1.1.1'],
	['statusType', '1.0.0'],
])('the demo %s seeds', (schema, shippedVersion) => {
	const objects = objectsOf(schema)
	const keys = translatable(schema)

	it('exist', () => {
		expect(objects.length).toBeGreaterThan(0)
		expect(keys.length).toBeGreaterThan(0)
	})

	it.each(objects.map((o) => [o['@self'].slug, o]))('%s has a Dutch and an English value for each label it sets', (slug, object) => {
		for (const key of keys) {
			if (object[key] === undefined) {
				continue
			}
			expect(object[key], `${slug}.${key}`).toEqual({
				nl: expect.any(String),
				en: expect.any(String),
			})
			expect(object[key].nl.trim(), `${slug}.${key}.nl`).not.toBe('')
			expect(object[key].en.trim(), `${slug}.${key}.en`).not.toBe('')
		}
	})

	it.each(objects.map((o) => [o['@self'].slug, o]))(`%s ships at a version above ${shippedVersion}`, (slug, object) => {
		expect(versionAbove(object['@self'].version, shippedVersion), `${slug} @self.version`).toBe(true)
	})
})

describe('the Dutch case type titles', () => {
	const titles = objectsOf('caseType').map((o) => o.title && o.title.nl)

	it('differ from every other shipped case type title, so no list shows two of one name', () => {
		const dir = path.join(ROOT, 'lib/Settings')
		const others = new Set()
		const files = ['dossiq_register.json', ...fs.readdirSync(path.join(dir, 'register.d')).map((f) => `register.d/${f}`)]
		for (const file of files) {
			if (file === 'register.d/46-demo-cases-english.json' || !file.endsWith('.json')) {
				continue
			}
			const objects = (JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8')).components || {}).objects || []
			for (const o of objects) {
				if (o && o['@self'] && o['@self'].schema === 'caseType' && typeof o.title === 'string') {
					others.add(o.title)
				}
			}
		}
		expect(others.size).toBeGreaterThan(0)
		for (const title of titles) {
			expect(others.has(title), title).toBe(false)
		}
	})
})

describe('the Dutch labels of the demo case types', () => {
	it('carry no German term beside the Dutch one, and moved their version for it', () => {
		for (const object of objectsOf('caseType')) {
			for (const key of translatable('caseType')) {
				const value = object[key]
				if (value && typeof value === 'object' && typeof value.nl === 'string') {
					expect(value.nl).not.toMatch(/Baugenehmigung|Förderantrag|Bürgerbeschwerde|Informationsfreiheitsgesetz/u)
				}
			}
			expect(versionAbove(object['@self'].version, '1.2.0')).toBe(true)
		}
	})
})
