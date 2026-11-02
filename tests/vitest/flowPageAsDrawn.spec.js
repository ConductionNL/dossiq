/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type flow page as board DqFlow draws it: a curated palette in four
 * groups, each step a card with the pill of the app that does it, edits to a
 * published flow saved as its next version, and an Active switch that keeps
 * its name.
 *
 * @spec exclude board look of the flow page (DqFlow), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (file) => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../..', file), 'utf8'))
const config = read('src/manifest.json').pages.find((page) => page.id === 'FlowDetail').config
const nl = read('l10n/nl.json').translations
const flow = read('lib/Settings/example-sets/zuiddrecht.d/60-regulier-case-type-flow.json').flows[0].data

describe('the flow page as board DqFlow draws it', () => {
	it('curates the palette in four groups, in Dutch', () => {
		expect(config.paletteGroups.map((group) => nl[group.label] || group.label)).toEqual(['Dossiq', 'OpenRegister', 'Integriq', 'Filinq'])
		const labels = config.paletteGroups.flatMap((group) => group.entries.map((entry) => nl[entry.label]))
		expect(labels).toEqual(['Status zetten', 'Deelzaak aanmaken', 'Taak aanmaken', 'Persoon vragen', 'Bericht of e-mail', 'Besluit publiceren', 'Veld schrijven', 'Beslistabel', 'Externe aanroep', 'Document maken'])
		config.paletteGroups.flatMap((group) => group.entries).filter((entry) => entry.short).forEach((entry) => expect(nl[entry.short]).toBeTruthy())
	})

	it('names a pill for every step of the example flow but its start and end', () => {
		const types = config.paletteGroups.flatMap((group) => group.entries.map((entry) => entry.type))
		flow.nodes.filter((node) => !['openregister.trigger-object', 'openregister.end'].includes(node.type))
			.forEach((node) => expect(types).toContain(node.type))
	})

	it('draws steps as cards, saves edits as the next version and keeps the switch named Active', () => {
		expect(config.nodeLook).toBe('card')
		expect(config.saveAsVersion).toBe(true)
		expect(config.metaParts).toEqual(['version'])
		expect(nl[config.metaNote]).toBe('wijzigingen gelden voor nieuwe zaken')
		expect(config.toggleLabel).toBe('fixed')
	})
})
