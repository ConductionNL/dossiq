// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case-type field vocabulary is OpenRegister's, not dossiq's.
 *
 * dossiq lets an administrator author schema properties through its own form,
 * so two lists have to agree: the `propertyType` enum the Properties tab
 * offers, and the `x-openregister-extends-form.map` that carries a definition
 * into the case form. Both lived in `dossiq_register.json` with nothing
 * comparing them to the engine, and they drifted exactly as far as you would
 * expect: the editor offered eight types while OpenRegister's validator
 * accepted nineteen, and five of the eight (`date`, `url`, `email`, `enum`,
 * `json`) were not types at all.
 *
 * This is the check that makes the drift a build failure. It reads the
 * vocabulary snapshot, which is generated from `PropertyValidatorHandler`'s
 * own tables, and refuses a type the engine cannot check and a map key nobody
 * defines.
 *
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/dossiq_register.json'
import {
	LEGACY_TYPE_ALIASES,
	PENDING_PLATFORM_KEYS,
	RENDERER_ROLES,
} from '../../src/services/propertyVocabulary.js'
import { VOCABULARY_SNAPSHOT } from '../../src/services/propertyVocabularySnapshot.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const schemas = register.components.schemas
const propertyDefinition = schemas.propertyDefinition
const definitionKeys = Object.keys(propertyDefinition.properties)
const declaration = schemas.case.properties.caseType['x-openregister-extends-form']
const map = declaration.map
const offeredTypes = propertyDefinition.properties.propertyType.enum
const vocabularyTypes = VOCABULARY_SNAPSHOT.types.map((row) => row.type)
const vocabularyKeys = VOCABULARY_SNAPSHOT.keys

describe('the vocabulary snapshot is the engine own list', () => {
	it('publishes the counts PropertyValidatorHandler holds', () => {
		// The modifier and key counts moved by one on 2026-09-18, when
		// openregister#3883 published `conceptScheme` and this app began
		// forwarding it. ONLY by one: the live vocabulary holds 99 keys against
		// this snapshot's 90, and the rest of that drift is deliberately left
		// where it is. A snapshot is refreshed by taking it again from the
		// endpoint, which is its own piece of work; nudging the numbers here to
		// match a live instance would turn this assertion into a rubber stamp.
		expect(VOCABULARY_SNAPSHOT.counts).toEqual({
			types: 19,
			constraints: 33,
			modifiers: 37,
			passthrough: 19,
			formats: 33,
			keys: 90,
		})
	})

	it('counts what it actually carries', () => {
		expect(VOCABULARY_SNAPSHOT.types).toHaveLength(
			VOCABULARY_SNAPSHOT.counts.types,
		)
		expect(VOCABULARY_SNAPSHOT.constraints).toHaveLength(
			VOCABULARY_SNAPSHOT.counts.constraints,
		)
		expect(VOCABULARY_SNAPSHOT.modifiers).toHaveLength(
			VOCABULARY_SNAPSHOT.counts.modifiers,
		)
		expect(VOCABULARY_SNAPSHOT.passthrough).toHaveLength(
			VOCABULARY_SNAPSHOT.counts.passthrough,
		)
		expect(VOCABULARY_SNAPSHOT.keys).toHaveLength(
			VOCABULARY_SNAPSHOT.counts.keys,
		)
	})

	it('carries the two formats that were missing when the lists drifted', () => {
		const formats = VOCABULARY_SNAPSHOT.types.find(
			(row) => row.type === 'string',
		).formats
		expect(formats).toContain('bsn')
		expect(formats).toContain('user')
	})
})

describe('no type is offered that the engine cannot check', () => {
	it('offers only vocabulary types, plus the five dossiq used to offer', () => {
		const unknown = offeredTypes.filter(
			(type) =>
				!vocabularyTypes.includes(type)
				&& !Object.hasOwn(LEGACY_TYPE_ALIASES, type),
		)
		expect(unknown).toEqual([])
	})

	it('offers every type the vocabulary holds', () => {
		const missing = vocabularyTypes.filter(
			(type) => !offeredTypes.includes(type),
		)
		expect(missing).toEqual([])
	})

	it('names a vocabulary type for each of the five it keeps', () => {
		expect(Object.keys(LEGACY_TYPE_ALIASES)).toEqual([
			'date',
			'url',
			'email',
			'enum',
			'json',
		])
		Object.entries(LEGACY_TYPE_ALIASES).forEach(([legacy, alias]) => {
			expect(offeredTypes, `${legacy} is still accepted`).toContain(legacy)
			expect(vocabularyTypes, `${legacy} maps onto a real type`).toContain(
				alias.type,
			)
			if (alias.format) {
				const formats = VOCABULARY_SNAPSHOT.types.find(
					(row) => row.type === alias.type,
				).formats
				expect(formats, `${legacy} maps onto a real format`).toContain(
					alias.format,
				)
			}
		})
	})
})

describe('the extends-form map forwards nothing nobody defines', () => {
	it('names the app and the form it speaks for', () => {
		expect(declaration.app).toBe('dossiq')
		expect(declaration.form).toBeTruthy()
	})

	it('forwards only vocabulary keys and documented renderer roles', () => {
		const unknown = Object.keys(map).filter(
			(role) =>
				!vocabularyKeys.includes(role)
				&& !Object.hasOwn(RENDERER_ROLES, role),
		)
		expect(unknown).toEqual([])
	})

	it('states a reason for every role the vocabulary does not name', () => {
		Object.entries(RENDERER_ROLES).forEach(([role, reason]) => {
			expect(vocabularyKeys, `${role} is the renderer own`).not.toContain(role)
			expect(reason.length, `${role} says why`).toBeGreaterThan(20)
		})
	})

	it('reads every definition field it forwards', () => {
		const missing = Object.values(map).filter(
			(field) => !definitionKeys.includes(field),
		)
		expect(missing).toEqual([])
	})

	it('forwards each definition field once, to one key', () => {
		const targets = Object.keys(map)
		const sources = Object.values(map)
		expect(new Set(targets).size).toBe(targets.length)
		expect(new Set(sources).size).toBe(sources.length)
	})

	it('forwards every key this change added to the definition', () => {
		const added = [
			'format',
			'pattern',
			'minimum',
			'maximum',
			'items',
			'ref',
			'calculation',
		]
		added.forEach((field) => {
			expect(definitionKeys, `${field} is declared`).toContain(field)
			expect(Object.values(map), `${field} reaches the case form`).toContain(
				field,
			)
		})
	})

	it('forwards the type, or the enum is decoration', () => {
		expect(map.type).toBe('propertyType')
	})
})

describe('a computed field declares JSON, never Twig', () => {
	it('forwards the calculation the engine evaluates', () => {
		expect(map.calculation).toBe('calculation')
		expect(vocabularyKeys).toContain('calculation')
	})

	it('does not forward the Twig key, which the vocabulary also holds', () => {
		expect(vocabularyKeys).toContain('computed')
		expect(Object.keys(map)).not.toContain('computed')
		expect(definitionKeys).not.toContain('computed')
	})
})

describe('a key the platform has not published is not forwarded', () => {
	it('keeps the declared source on the definition and out of the map', () => {
		Object.entries(PENDING_PLATFORM_KEYS).forEach(([field, entry]) => {
			expect(definitionKeys, `${field} is stored`).toContain(field)
			expect(
				Object.values(map),
				`${field} is not forwarded yet`,
			).not.toContain(field)
			expect(entry.reason.length, `${field} says why`).toBeGreaterThan(20)
		})
	})

	it('fails once the vocabulary does hold the key, which is the reminder', () => {
		Object.values(PENDING_PLATFORM_KEYS).forEach((entry) => {
			expect(
				vocabularyKeys,
				`${entry.key} is published now: forward it and drop this entry`,
			).not.toContain(entry.key)
		})
	})

	it('ships no resolver for a declared source', () => {
		expect(definitionKeys).toContain('propertySource')
		const hits = []
		const walk = (dir) => {
			readdirSync(dir, { withFileTypes: true }).forEach((entry) => {
				const full = join(dir, entry.name)
				if (entry.isDirectory()) {
					walk(full)
					return
				}
				if (!/\.(js|ts|vue|php)$/.test(entry.name)) {
					return
				}
				const body = readFileSync(full, 'utf8')
				if (
					/PropertySourceProvider|resolvePropertySource|suggestFromSource/.test(
						body,
					)
				) {
					hits.push(full)
				}
			})
		}
		walk(join(ROOT, 'src'))
		walk(join(ROOT, 'lib'))
		expect(hits, 'integriq resolves a source, dossiq declares it').toEqual([])
	})
})
