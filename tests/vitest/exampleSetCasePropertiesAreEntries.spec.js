/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case schema stores `properties` as a list of `{ name, value }` entries;
 * an example-set case that writes an object is refused by the install check.
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const DIR = path.resolve(__dirname, '../../lib/Settings/example-sets')
const files = [path.join(DIR, 'zuiddrecht.json')]
	.concat(fs.readdirSync(path.join(DIR, 'zuiddrecht.d')).filter((name) => name.endsWith('.json')).map((name) => path.join(DIR, 'zuiddrecht.d', name)))

const schemas = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../lib/Settings/dossiq_register.json'), 'utf8')).components.schemas

describe('the example set fragments', () => {
	it('declare an object of their own before the objects that refer to it', () => {
		const wrong = []
		for (const file of files) {
			const seen = new Set()
			const objects = JSON.parse(fs.readFileSync(file, 'utf8')).objects || []
			const own = new Set(objects.map((object) => object.key))
			for (const object of objects) {
				for (const match of JSON.stringify(object).matchAll(/\{\{object:([^.}]+)\.id\}\}/g)) {
					if (own.has(match[1]) && !seen.has(match[1]) && match[1] !== object.key) {
						wrong.push(`${path.basename(file)}: ${object.key} -> ${match[1]}`)
					}
				}
				seen.add(object.key)
			}
		}
		expect(wrong).toEqual([])
	})
})

describe('the example set cases', () => {
	it('send as JSON text only the fields their schema keeps as text', () => {
		const wrong = []
		for (const file of files) {
			for (const object of (JSON.parse(fs.readFileSync(file, 'utf8')).objects || [])) {
				const declared = schemas[object.schema]?.properties || {}
				for (const field of (object.jsonFields || [])) {
					if (declared[field] && declared[field].type !== 'string') {
						wrong.push(`${object.key}.${field} is ${declared[field].type}`)
					}
				}
			}
		}
		expect(wrong).toEqual([])
	})

	it('write their properties as a list of named entries, never as an object', () => {
		const wrong = []
		for (const file of files) {
			for (const object of (JSON.parse(fs.readFileSync(file, 'utf8')).objects || [])) {
				const stored = object.schema === 'case' ? object.data?.properties : undefined
				if (stored !== undefined && !(Array.isArray(stored) && stored.every((entry) => typeof entry.name === 'string' && typeof entry.value === 'string'))) {
					wrong.push(object.key)
				}
			}
		}
		expect(wrong).toEqual([])
	})
})
