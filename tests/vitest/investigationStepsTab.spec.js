// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSociaalDomein "Onderzoek 2": the investigation steps live in the
 * case type (a sensitive list-of-records answer) and the Onderzoek tab lists
 * and counts them from the case the page read.
 *
 * @spec openspec/changes/investigation-steps-in-the-case-type/specs/case-page/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const layout = readJson('src', 'menu-layout.simple.json')
const nl = readJson('l10n', 'nl.json').translations
const packageDir = ['lib', 'Settings', 'packages', 'jeugdwet']
const caseType = readJson(...packageDir, 'case-type.json')
const example = readJson(...packageDir, 'examples', '20-case.json').rows[0]

function find(node, test) {
	if (Array.isArray(node)) {
		return node.map((entry) => find(entry, test)).find(Boolean) || null
	}
	if (node && typeof node === 'object') {
		return test(node) ? node : Object.values(node).map((entry) => find(entry, test)).find(Boolean) || null
	}
	return null
}

const SOURCE = { path: 'sensitiveProperties', where: { name: 'onderzoeksstappen' }, valuePath: 'value' }

describe('the Onderzoek tab', () => {
	it('is a sensitive list-of-records field of Melding Jeugdwet', () => {
		const field = caseType.propertyDefinitions.find((definition) => definition.name === 'onderzoeksstappen')
		expect(field).toMatchObject({ propertyType: 'array', sensitive: true, hideOnCreate: true, caseType: caseType.caseType.id })
		expect(Object.keys(field.items.properties)).toEqual(['kind', 'date', 'by', 'withWhom', 'summary', 'consentRef', 'followUp'])
		// 1.9.0 brought the investigation steps; a later package version keeps them.
		const [major, minor] = readJson(...packageDir, 'package.json').version.split('.').map(Number)
		expect(major > 1 || (major === 1 && minor >= 9)).toBe(true)
	})

	it('lists and counts the steps from the case, as Onderzoek', () => {
		const tab = find(layout, (node) => node.widgetId === 'case-investigation-steps')
		expect(nl[tab.label]).toBe('Onderzoek')
		expect(tab.countObjectRows).toEqual(SOURCE)
		const widget = find(layout, (node) => node.id === 'case-investigation-steps' && node.type)
		expect(widget.type).toBe('record-list')
		expect(widget.content.objectRows).toEqual(SOURCE)
	})

	it('gives the Oosthaven case two steps, "Onderzoek 2"', () => {
		const answer = example.sensitiveProperties.find((entry) => entry.name === 'onderzoeksstappen')
		const field = caseType.propertyDefinitions.find((definition) => definition.name === 'onderzoeksstappen')
		expect(answer.propertyDefinition).toBe(field.id)
		const steps = JSON.parse(answer.value)
		expect(steps.map((step) => step.kind)).toEqual(['gesprek', 'informatie-opgevraagd'])
		expect(steps.every((step) => /^\{\{date:-\d+\}\}$/.test(step.date))).toBe(true)
	})
})
