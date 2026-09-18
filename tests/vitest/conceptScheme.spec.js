// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A field's choices come from one place, and it is always the same place.
 *
 * A property can carry an inline list and a binding to a SKOS concept scheme
 * in OpenRegister. Two sources on one definition is an authoring mistake, and
 * the dangerous version of that mistake is the silent one: the form shows the
 * typed list, the case renders the scheme, and nothing says which a handler
 * will actually see.
 *
 * So the precedence has one home, and this is the test that keeps it there.
 * The scheme wins when it is set, the inline list wins when it is not, and a
 * definition carrying both is reported as competing rather than quietly
 * resolved.
 *
 * @spec openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/dossiq_register.json'
import {
	hasCompetingSources,
	inlineValuesOf,
	optionSourceFor,
	schemeOf,
	SOURCE_INLINE,
	SOURCE_NONE,
	SOURCE_SCHEME,
} from '../../src/services/conceptScheme.js'
import { PENDING_PLATFORM_KEYS } from '../../src/services/propertyVocabulary.js'
import { VOCABULARY_SNAPSHOT } from '../../src/services/propertyVocabularySnapshot.js'

const propertyDefinition = register.components.schemas.propertyDefinition
const definitionKeys = Object.keys(propertyDefinition.properties)
const map = register.components.schemas.case.properties.caseType[
	'x-openregister-extends-form'
].map

describe('the binding is declared on the definition', () => {
	it('carries conceptScheme as a string', () => {
		expect(definitionKeys).toContain('conceptScheme')
		expect(propertyDefinition.properties.conceptScheme.type).toBe('string')
	})

	it('says what the field is for, at length', () => {
		const described = propertyDefinition.properties.conceptScheme.description
		expect(described.length).toBeGreaterThan(80)
		expect(described).toContain('OpenRegister')
	})

	it('moves its schema version, or no instance reconciles the new key', () => {
		expect(propertyDefinition.version).toBe('1.4.0')
	})

	it('is not forwarded to the case form, because nobody published the key', () => {
		const pending = PENDING_PLATFORM_KEYS.conceptScheme
		expect(pending.key).toBe('x-openregister-concept-scheme')
		expect(pending.owner).toBe('openregister')
		expect(pending.reason.length).toBeGreaterThan(20)
		expect(Object.values(map)).not.toContain('conceptScheme')
		expect(VOCABULARY_SNAPSHOT.keys).not.toContain(pending.key)
	})
})

describe('the scheme rules when it is set', () => {
	it('offers the scheme concepts when only a scheme is bound', () => {
		const answer = optionSourceFor({ conceptScheme: 'wijken' })
		expect(answer.source).toBe(SOURCE_SCHEME)
		expect(answer.scheme).toBe('wijken')
		expect(answer.competing).toBe(false)
	})

	it('wins over an inline list, and says the two compete', () => {
		const definition = {
			conceptScheme: 'wijken',
			enumValues: ['Centrum', 'Noord'],
		}
		const answer = optionSourceFor(definition)
		expect(answer.source).toBe(SOURCE_SCHEME)
		expect(answer.scheme).toBe('wijken')
		expect(answer.competing).toBe(true)
		expect(hasCompetingSources(definition)).toBe(true)
	})

	it('reads a reference typed with a stray space as the same reference', () => {
		expect(schemeOf({ conceptScheme: '  wijken ' })).toBe('wijken')
		expect(optionSourceFor({ conceptScheme: '  wijken ' }).source).toBe(
			SOURCE_SCHEME,
		)
	})

	it('treats a blank binding as no binding', () => {
		expect(schemeOf({ conceptScheme: '   ' })).toBe('')
		expect(optionSourceFor({ conceptScheme: '   ' }).source).toBe(SOURCE_NONE)
	})

	it('refuses to read a non-string as a binding', () => {
		expect(schemeOf({ conceptScheme: { id: 'wijken' } })).toBe('')
		expect(schemeOf({ conceptScheme: 7 })).toBe('')
		expect(schemeOf(null)).toBe('')
	})
})

describe('the inline list rules when no scheme is bound', () => {
	it('offers the typed values', () => {
		const answer = optionSourceFor({ enumValues: ['ja', 'nee'] })
		expect(answer.source).toBe(SOURCE_INLINE)
		expect(answer.values).toEqual(['ja', 'nee'])
		expect(answer.competing).toBe(false)
	})

	it('drops the empty lines a textarea leaves behind', () => {
		expect(inlineValuesOf({ enumValues: ['ja', '', '  ', 'nee'] })).toEqual([
			'ja',
			'nee',
		])
	})

	it('is not a list when the stored value is not an array', () => {
		expect(inlineValuesOf({ enumValues: 'ja' })).toEqual([])
		expect(inlineValuesOf({})).toEqual([])
	})

	it('does not compete with an empty scheme', () => {
		expect(
			hasCompetingSources({ conceptScheme: '', enumValues: ['ja'] }),
		).toBe(false)
	})
})

describe('a field with neither takes any answer its type allows', () => {
	it('answers none', () => {
		const answer = optionSourceFor({ name: 'Toelichting' })
		expect(answer.source).toBe(SOURCE_NONE)
		expect(answer.scheme).toBe('')
		expect(answer.values).toEqual([])
		expect(answer.competing).toBe(false)
	})
})
