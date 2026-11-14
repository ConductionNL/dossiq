/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Reports page's Archiving card opens nothing until a retention report
 * exists, and says so; it does not route to Cases, which menu entries use.
 *
 * @spec exclude the retention report has no spec yet; this keeps the card from routing to Cases
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const reports = read('src/manifest.json').pages.find((page) => page.id === 'Reports')
const card = reports.config.cards.find((entry) => entry.id === 'Archiving')
const nl = read('l10n/nl.json').translations

describe('the Archiving report card', () => {
	it('names no route, so it cannot double a menu entry', () => {
		expect(card.route).toBeUndefined()
		expect(card.query).toBeUndefined()
		expect(card.href).toBe('#')
	})

	it('is unavailable and says so in both languages', () => {
		expect(card.requiresApp).toBe('dossiq-retention-report')
		expect(card.missingText).toBe('This report is not available yet.')
		expect(nl[card.missingText]).toBe('Dit rapport is nog niet beschikbaar.')
	})
})
