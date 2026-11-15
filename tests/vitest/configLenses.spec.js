// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * A lens that names an app setting (`@config.<key>`) is offered only when the
 * setting holds a value: the contacts and organisations lists open on the
 * municipality's city (boards DqContacten, DqOrganisaties), and an instance
 * without one opens on All instead of a lens that filters on nothing.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { withConfiguredLenses } from '../../src/utils/configLenses.js'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

/**
 * A page's lenses after the config is applied.
 *
 * @param {string} id The page id.
 * @param {object} config The app config.
 * @return {Array<object>} The lenses.
 */
function lensesOf(id, config) {
	const built = withConfiguredLenses(structuredClone(manifest), config)
	return built.pages.find((page) => page.id === id).config.quickFilters
}

describe('withConfiguredLenses', () => {
	it('keeps a lens whose setting has a value', () => {
		const lenses = [{ label: 'All', filter: {} }, { label: 'In @config.city', filter: { city: '@config.city' }, default: true }]
		const out = withConfiguredLenses({ pages: [{ id: 'P', config: { quickFilters: lenses } }] }, { city: 'Zuiddrecht' })
		expect(out.pages[0].config.quickFilters).toEqual(lenses)
	})

	it('drops a lens whose setting is empty, and hands its default to the first lens left', () => {
		const lenses = [{ label: 'All', filter: {} }, { label: 'In @config.city', filter: { city: '@config.city' }, default: true }]
		const out = withConfiguredLenses({ pages: [{ id: 'P', config: { quickFilters: lenses } }] }, { city: '  ' })
		expect(out.pages[0].config.quickFilters).toEqual([{ label: 'All', filter: {}, default: true }])
	})

	it('keeps a lens whose token is optional', () => {
		const lenses = [{ label: 'All', filter: { city: '@config.city?' } }]
		const out = withConfiguredLenses({ pages: [{ id: 'P', config: { quickFilters: lenses } }] }, {})
		expect(out.pages[0].config.quickFilters).toEqual(lenses)
	})
})

describe('withConfiguredLenses on default filters', () => {
	it('keeps a default filter whose setting has a value', () => {
		const out = withConfiguredLenses({ pages: [{ id: 'P', config: { defaultFilters: { city: '@config.city', kind: 'x' } } }] }, { city: 'Zuiddrecht' })
		expect(out.pages[0].config.defaultFilters).toEqual({ city: '@config.city', kind: 'x' })
	})

	it('drops a default filter whose setting is empty, and the map when nothing is left', () => {
		const one = withConfiguredLenses({ pages: [{ id: 'P', config: { defaultFilters: { city: '@config.city', kind: 'x' } } }] }, {})
		expect(one.pages[0].config.defaultFilters).toEqual({ kind: 'x' })
		const none = withConfiguredLenses({ pages: [{ id: 'P', config: { defaultFilters: { city: '@config.city' } } }] }, {})
		expect(none.pages[0].config).not.toHaveProperty('defaultFilters')
	})
})

describe.each([
	['Contacts', 'residence.city', 'Place of residence: @config.municipalityCity', 'Woonplaats: @config.municipalityCity'],
])('the %s list', (id, field, activeLabel, dutch) => {
	it('opens on the municipality city lens when the city is set', () => {
		const lenses = lensesOf(id, { municipalityCity: 'Zuiddrecht' })
		const lens = lenses.find((l) => l.default === true)
		expect(lens.filter).toEqual({ [field]: '@config.municipalityCity' })
		expect(lens.activeLabel).toBe(activeLabel)
		expect(nl[activeLabel]).toBe(dutch)
		expect(nl[lens.label]).toBeTruthy()
		expect(lenses.filter((l) => l.default === true)).toHaveLength(1)
	})

	it('opens on All without a city', () => {
		const lenses = lensesOf(id, { municipalityCity: '' })
		expect(lenses.find((l) => l.default === true).label).toBe('All')
		expect(JSON.stringify(lenses)).not.toContain('@config.')
	})
})

describe('the Organisations list (DqOrganisaties: All, with "Plaats: Zuiddrecht" in the Filter panel)', () => {
	/**
	 * The Organisations page config after the config is applied.
	 *
	 * @param {object} config The app config.
	 * @return {object} The page config.
	 */
	const pageOf = (config) => withConfiguredLenses(structuredClone(manifest), config).pages.find((page) => page.id === 'Organisations').config

	it('has no lens on the city, and opens on All filtered to the municipality city', () => {
		const config = pageOf({ municipalityCity: 'Zuiddrecht' })
		expect(config.quickFilters.some((lens) => lens.filter && 'address.place' in lens.filter)).toBe(false)
		expect(config.quickFilters.some((lens) => lens.default === true)).toBe(false)
		expect(config.defaultFilters).toEqual({ 'address.place': '@config.municipalityCity' })
		expect(nl[config.filterLabels['address.place'].label]).toBe('Plaats')
	})

	it('opens unfiltered without a city', () => {
		const config = pageOf({ municipalityCity: '' })
		expect(config).not.toHaveProperty('defaultFilters')
		expect(JSON.stringify(config.quickFilters)).not.toContain('@config.')
	})
})
