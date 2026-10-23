/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqFlow as configuration: the curated palette in the case worker's words,
 * steps as cards with their app pill, edits saved as the next version
 * ("versie 4 · wijzigingen gelden voor nieuwe zaken"), and a realistic case
 * type flow in the Zuiddrecht example set so the page shows content.
 *
 * @spec openspec/changes/flow-page-board-config/specs/flows/spec.md#requirement-the-case-type-flow-page-follows-board-dqflow
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/manifest.json').pages.find((page) => page.id === 'FlowDetail').config
const nl = read('l10n/nl.json').translations
const example = read('lib/Settings/example-sets/zuiddrecht.json')

describe('the flow page as board DqFlow draws it', () => {
	it('curates the palette in four groups, in Dutch', () => {
		expect(config.paletteGroups.map((group) => group.label)).toEqual(['Dossiq', 'OpenRegister', 'Integriq', 'Filinq'])
		const labels = config.paletteGroups.flatMap((group) => group.entries.map((entry) => nl[entry.label]))
		expect(labels).toEqual(['Status zetten', 'Deelzaak aanmaken', 'Taak aanmaken', 'Persoon vragen', 'Besluit publiceren', 'Veld schrijven', 'Beslistabel', 'Bericht of e-mail', 'Externe aanroep', 'Document maken'])
		config.paletteGroups.flatMap((group) => group.entries).filter((entry) => entry.short).forEach((entry) => expect(nl[entry.short]).toBeTruthy())
	})

	it('draws steps as cards and saves edits as the next version', () => {
		expect(config.nodeLook).toBe('card')
		expect(config.saveAsVersion).toBe(true)
		expect(config.metaParts).toEqual(['version'])
		expect(nl[config.metaNote]).toBe('wijzigingen gelden voor nieuwe zaken')
	})
})

describe('the example case type flow', () => {
	const flow = example.flows.find((row) => row.key === 'flow-omgevingsvergunning-regulier').data

	it('is the board flow, every step placed and named', () => {
		expect(flow.name).toBe('Omgevingsvergunning regulier')
		expect(flow.nodes.map((node) => node.name)).toEqual(['Zaak aangemaakt', 'Status: ontvangen', 'Volledigheid toetsen', 'Welstand nodig?', 'Ontvangstbericht', 'Deelzaak welstandsadvies', 'Besluit publiceren', 'Klaar'])
		flow.nodes.forEach((node) => expect(node.position).toEqual({ x: expect.any(Number), y: expect.any(Number) }))
	})

	it('runs from the start to an end on every line', () => {
		const ids = flow.nodes.map((node) => node.id)
		flow.edges.forEach((edge) => {
			expect(ids).toContain(edge.from)
			expect(ids).toContain(edge.to)
		})
		const ends = flow.nodes.filter((node) => node.type === 'openregister.end').map((node) => node.id)
		const leaves = ids.filter((id) => !flow.edges.some((edge) => edge.from === id))
		expect(leaves).toEqual(ends)
	})

	it('uses only steps the palette offers or the engine starts and ends with', () => {
		const offered = config.paletteGroups.flatMap((group) => group.entries.map((entry) => entry.type))
		flow.nodes.forEach((node) => expect([...offered, 'openregister.trigger-object', 'openregister.end']).toContain(node.type))
	})

	it('decides welstand with a decision table the set ships', () => {
		const ref = flow.nodes.find((node) => node.id === 'welstand').config.tableRef
		const key = /^\{\{object:([a-z0-9-]+)\.id\}\}$/.exec(ref)[1]
		const table = example.objects.find((object) => object.key === key)
		expect([table.register, table.schema, table.data.name]).toEqual(['decision-tables', 'decision-table', 'Welstandsgebieden Zuiddrecht'])
		expect(table.data.inputs.map((input) => input.name)).toEqual(Object.keys(flow.nodes.find((node) => node.id === 'welstand').config.inputMapping))
		table.data.rules.forEach((rule) => {
			expect(rule.inputEntries).toHaveLength(table.data.inputs.length)
			expect(rule.outputEntries).toHaveLength(table.data.outputs.length)
		})
	})

		it('asks a group the set creates', () => {
		const groups = example.groups.map((group) => group.group)
		expect(groups).toContain(flow.nodes.find((node) => node.id === 'volledigheid').config.assignee)
	})
})
