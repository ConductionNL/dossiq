/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Kadaster (BRK) and WOZ form writes the keys IntegrationMode reads, and
 * the API keys are write-only.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { kadasterFields } from '../../src/views/settings/adminFields.js'

const ROOT = path.resolve(__dirname, '../..')
const configKeys = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'Settings', 'ConfigKeys.php'),
	'utf8',
)
/**
 * The translate the fields call, filling `{name}` placeholders.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholder values.
 * @return {string} The text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? vars[key] : m))
}

describe('the Kadaster and WOZ form', () => {
	const fields = kadasterFields(t)

	it('asks for a source, an address and a key per binding', () => {
		expect(fields.map((field) => field.key)).toEqual([
			'integration.brk.mode',
			'integration.brk.baseUrl',
			'integration.brk.apiKey',
			'integration.woz.mode',
			'integration.woz.baseUrl',
			'integration.woz.apiKey',
		])
	})

	it.each(fields.map((field) => [field.key]))(
		'%s is a setting the settings endpoint saves',
		(key) => {
			expect(configKeys).toContain(`'${key}',`)
		},
	)

	it('takes the API keys as secrets, which are never shown back', () => {
		const secrets = fields.filter((field) => field.key.endsWith('.apiKey'))
		expect(secrets.map((field) => field.type)).toEqual(['secret', 'secret'])
	})

	it('offers the three modes IntegrationMode knows, off by default', () => {
		const mode = fields[0]
		expect(mode.options.map((option) => option.value)).toEqual([
			'log',
			'test',
			'live',
		])
		expect(mode.default).toBe('log')
	})
})
