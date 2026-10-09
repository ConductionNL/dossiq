/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every dossiq notification rule has a label in the user settings.
 *
 * The settings pane printed rule keys ("caseAssigned",
 * "substitutionRegisteredForSubstitute"). dossiq now hands CnAppRoot a label
 * per rule; this pins that every rule the register declares has one, keyed the
 * way OpenRegister lists it (`<schema slug>.<rule key>`), with a Dutch entry.
 *
 * @spec openspec/specs/notification-labels/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { notificationLabels } from '../../src/utils/notificationLabels.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

/**
 * Every `<schema slug>.<rule key>` the dossiq register files declare.
 *
 * @return {string[]} The rule ids.
 */
function declaredRules() {
	const files = [
		path.join('lib', 'Settings', 'dossiq_register.json'),
		...fs
			.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => path.join('lib', 'Settings', 'register.d', name)),
	]
	const rules = new Set()
	for (const file of files) {
		const schemas = readJson(file).components?.schemas ?? {}
		for (const [name, schema] of Object.entries(schemas)) {
			const block =
				schema['x-openregister-notifications']
				?? schema.configuration?.['x-openregister-notifications']
			for (const key of Object.keys(block ?? {})) {
				rules.add(`${schema.slug ?? name}.${key}`)
			}
		}
	}
	return [...rules].sort()
}

const labels = notificationLabels()
const rules = declaredRules()
const nl = readJson('l10n', 'nl.json').translations

describe('dossiq notification labels', () => {
	it('finds the declared rules, which is the control', () => {
		expect(rules).toContain('case.caseAssigned')
		expect(rules).toContain('substitution.substitutionRegisteredForSubstitute')
		expect(rules).toContain('workDigest.workDigestReady')
	})

	it.each(rules.map((rule) => [rule]))(
		'%s has a label that is not its key',
		(rule) => {
			const label = labels[rule]
			expect(label, `no label for ${rule}`).toBeTruthy()
			expect(label).not.toBe(rule.split('.').pop())
		},
	)

	it('has a Dutch entry for every label', () => {
		for (const label of Object.values(labels)) {
			expect(nl[label], `no Dutch for "${label}"`).toBeTruthy()
		}
	})
})
