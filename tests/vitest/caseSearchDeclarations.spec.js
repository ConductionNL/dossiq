// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every case field a person searches says how it is searched.
 *
 * 🔴 A DECLARED MATCH TYPE ALSO ENLISTS THE COLUMN IN THE FREE-TEXT SCAN.
 * `PropertySearchProfile::participatesInFreeText()` asks a different question
 * of a declared property than of a silent one: a silent property joins the
 * `_search` scan when it is a string whose format is not a date, and a
 * declared one joins it unless its type is `range`. So `matchType: exact` on
 * `isDraft` would put `isDraft::text ILIKE 'true'` into every free-text
 * search, and a search for the word "true" would return every draft. There is
 * no value meaning "filterable, never scanned", and `range` on a boolean is a
 * lie, so a boolean declares its control and nothing else. That is asserted
 * here rather than described in a note, because the failure is silent: the
 * extra rows look like a search that matched more.
 *
 * 🔴 THE VOCABULARIES ARE OPENREGISTER'S AND A WRONG VALUE IS REFUSED AT
 * SCHEMA SAVE. `PropertyValidatorHandler` checks `matchType` against
 * `PropertySearchProfile::MATCH_TYPES` and `inputControl` against
 * `INPUT_CONTROLS`. A typo here does not degrade to the old behaviour, it
 * fails the import, so the values are pinned against the constants.
 *
 * The register is merged the way `RegisterFragmentMerger` merges it, monolith
 * first and then `register.d/*.json` in sorted filename order, because that is
 * what OpenRegister imports. Asserting the fragment alone would pass while
 * a later fragment overwrote it.
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const FRAGMENT_DIR = path.join(ROOT, 'lib', 'Settings', 'register.d')

/** `PropertySearchProfile::MATCH_TYPES`, openregister development. */
const MATCH_TYPES = ['exact', 'prefix', 'range', 'fuzzy', 'fulltext']

/** `PropertySearchProfile::INPUT_CONTROLS`, openregister development. */
const INPUT_CONTROLS = ['text', 'select', 'multiselect', 'range', 'date-range', 'boolean']

/**
 * The properties that deliberately declare neither: an object, an array of
 * objects, or a JSON document held in a string column. None of them is typed
 * into a search box or offered as a filter, and declaring a match type would
 * put a serialised blob into the free-text scan.
 */
const SILENT = [
	'acknowledgementDuty', 'actionResult', 'activity', 'aanvulling',
	'attentionFlag', 'attentionFlagHistory', 'attentionMarkers',
	'casePlanState', 'commissieBesluit', 'conversations', 'geometry',
	'handoverRecord', 'intakeRefusal', 'majorChannel', 'missingFields',
	'outboundCommunications', 'properties', 'publications', 'relatedCases',
	'riskAssessment', 'skippedPhases', 'statusDwellTotals', 'statusHistory',
	'taskAttachments', 'toetsRegisterB', 'tweedeToets', 'voorbereiding',
]

/**
 * Deep-merge an override onto a base, as `RegisterFragmentMerger::deepMerge`
 * does: objects key by key, lists concatenated, scalars overwritten.
 *
 * @param {object} base The base value.
 * @param {object} over The override value.
 * @return {object} The merged value.
 */
function deepMerge(base, over) {
	const out = { ...base }
	Object.entries(over).forEach(([key, value]) => {
		const current = out[key]
		if (Array.isArray(value) && Array.isArray(current)) {
			out[key] = current.concat(value)
			return
		}
		if (
			value && typeof value === 'object' && !Array.isArray(value)
			&& current && typeof current === 'object' && !Array.isArray(current)
		) {
			out[key] = deepMerge(current, value)
			return
		}
		out[key] = value
	})
	return out
}

/**
 * The `case` properties OpenRegister actually imports.
 *
 * @return {object} The merged property map.
 */
function mergedCaseProperties() {
	const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))
	let register = readJson(path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'))
	fs.readdirSync(FRAGMENT_DIR)
		.filter((name) => name.endsWith('.json'))
		.sort()
		.forEach((name) => {
			register = deepMerge(register, readJson(path.join(FRAGMENT_DIR, name)))
		})
	return register.components.schemas.case.properties
}

const properties = mergedCaseProperties()

describe('the case declares how each of its fields is searched', () => {
	it('uses only match types and input controls openregister accepts', () => {
		const offenders = Object.entries(properties)
			.filter(([, definition]) => (
				(definition.matchType !== undefined && !MATCH_TYPES.includes(definition.matchType))
				|| (definition.inputControl !== undefined && !INPUT_CONTROLS.includes(definition.inputControl))
			))
			.map(([name, definition]) => `${name}: ${definition.matchType}/${definition.inputControl}`)

		expect(offenders).toEqual([])
	})

	it('declares an input control on every property a list can filter on', () => {
		const undeclared = Object.entries(properties)
			.filter(([name]) => !SILENT.includes(name))
			.filter(([, definition]) => definition.inputControl === undefined)
			.map(([name]) => name)

		// A new case property lands here on purpose: either it says how it is
		// searched, or it joins SILENT with a reason.
		expect(undeclared).toEqual([])
	})

	it('gives a boolean a control and never a match type', () => {
		const booleans = Object.entries(properties)
			.filter(([, definition]) => definition.type === 'boolean')

		expect(booleans.length).toBeGreaterThan(0)
		booleans.forEach(([name, definition]) => {
			expect(`${name}:${definition.inputControl}`).toBe(`${name}:boolean`)
			expect(`${name}:${definition.matchType}`).toBe(`${name}:undefined`)
		})
	})

	it('reads the identifier whole and the prose by substring', () => {
		expect(properties.identifier.matchType).toBe('exact')
		expect(properties.title.matchType).toBe('fulltext')
		expect(properties.description.matchType).toBe('fulltext')
	})

	it('brackets every date rather than matching a term against it', () => {
		const dates = Object.entries(properties)
			.filter(([name, definition]) => (
				!SILENT.includes(name)
				&& ['date', 'date-time'].includes(definition.format)
			))

		expect(dates.length).toBeGreaterThan(10)
		dates.forEach(([name, definition]) => {
			expect(`${name}:${definition.matchType}`).toBe(`${name}:range`)
			expect(`${name}:${definition.inputControl}`).toBe(`${name}:date-range`)
		})
	})

	it('picks a status, a type and a priority from a list', () => {
		;['status', 'caseType', 'priority', 'impact', 'urgency', 'confidentiality'].forEach((name) => {
			expect(`${name}:${properties[name].matchType}`).toBe(`${name}:exact`)
			expect(`${name}:${properties[name].inputControl}`).toBe(`${name}:select`)
		})
	})

	it('matches a tag loosely and a party name from the front', () => {
		expect(properties.tags.matchType).toBe('fuzzy')
		expect(properties.initiatorDisplayName.matchType).toBe('prefix')
		expect(properties.competentAuthority.matchType).toBe('prefix')
	})

	it('keeps the facetable flags the monolith already carried', () => {
		expect(properties.tags.facetable).toBe(true)
		expect(properties.status.facetable).toBe(true)
		expect(properties.isDraft.facetable).toBe(true)
	})
})
