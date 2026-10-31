/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin Koppelingen table shows dossiq's connection declarations, which
 * integriq stores in English as written, through the app's translate. Every
 * title and description therefore needs a Dutch entry, or DqInstellingen
 * reads English rows.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const connections = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'connections.json'), 'utf8'),
).connections
const nl = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8'),
).translations

describe('the connection declarations', () => {
	it('declares connections to check', () => {
		expect(connections.length).toBeGreaterThan(5)
	})

	it.each(
		connections.flatMap((connection) =>
			[connection.title, connection.description]
				.filter(Boolean)
				.map((text) => [connection.key, text]),
		),
	)('%s: "%s" has a Dutch entry', (key, text) => {
		expect(nl[text], `nl entry for "${text}"`).toBeTruthy()
	})
})
