// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The receipt confirmation card on the case page's Contact tab reads in
 * words (board DqZaakContact): dates formatted, the outbound messages as
 * lines instead of a table of stored codes.
 *
 * @spec openspec/specs/receipt-confirmation-card/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

let pageConfig = {}
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => (key === 'config' ? pageConfig : fallback) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ collections: {} }) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({ subCaseCounts: {} }) }))

const { default: formatters } = await import('../../src/services/formatters.js')

/**
 * Find a widget by id anywhere in a layout tree.
 *
 * @param {unknown} node The tree.
 * @param {string} id The widget id.
 * @return {object|null} The widget.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const hit = findWidget(item, id)
			if (hit) {
				return hit
			}
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
			return node
		}
		for (const value of Object.values(node)) {
			const hit = findWidget(value, id)
			if (hit) {
				return hit
			}
		}
	}
	return null
}

const layout = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))

describe('the outboundMessages formatter', () => {
	it('writes each message as what, through which channel and when', () => {
		const sentAt = '2026-10-03T12:32:00Z'
		const when = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(sentAt))
		expect(formatters.outboundMessages([{ moment: 'acknowledgement', channel: 'portal-inbox', sentAt }]))
			.toBe(`Receipt confirmation via the portal, ${when}`)
	})

	it('names the portal as portaliq names it', () => {
		pageConfig = { portalName: 'Mijn Zuiddrecht' }
		try {
			expect(formatters.outboundMessages([{ moment: 'acknowledgement', channel: 'portal-inbox', sentAt: '2026-10-03T12:32:00Z' }]))
				.toMatch(/^Receipt confirmation via Mijn Zuiddrecht, /)
		} finally {
			pageConfig = {}
		}
	})

	it('joins several messages and marks one nothing took', () => {
		const text = formatters.outboundMessages([
			{ moment: 'decision', channel: 'email', sentAt: '2026-10-05T08:00:00Z' },
			{ moment: 'stage', channel: '' },
		])
		expect(text.split('; ')).toHaveLength(2)
		expect(text.startsWith('Decision via email, ')).toBe(true)
		expect(text.endsWith('Status update, not sent')).toBe(true)
	})

	it('is empty without messages', () => {
		expect(formatters.outboundMessages(null)).toBe('')
		expect(formatters.outboundMessages([])).toBe('')
	})
})

describe('the receipt confirmation card', () => {
	const card = findWidget(layout, 'case-acknowledgement-card')

	it('lists its facts in the board order', () => {
		const order = (key) => card.content.overrides[key].order
		const keys = ['receivedAt', 'receivedOutsideWorkingHours', 'termStartsAt', 'outboundCommunications', 'acknowledgementDuty']
		expect([...keys].sort((a, b) => order(a) - order(b))).toEqual(keys)
		expect(keys.every((key) => typeof order(key) === 'number')).toBe(true)
	})

	it('reads the dates and the messages through formatters', () => {
		expect(card.content.overrides.receivedAt.formatter).toBe('datetime')
		expect(card.content.overrides.termStartsAt.formatter).toBe('date')
		expect(card.content.overrides.outboundCommunications.formatter).toBe('outboundMessages')
	})
})

describe('the receipt confirmation section of the full case page', () => {
	const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
	const card = findWidget(manifest, 'case-acknowledgement')

	it('reads the same way', () => {
		expect(card.content.overrides.receivedAt.formatter).toBe('datetime')
		expect(card.content.overrides.termStartsAt.formatter).toBe('date')
		expect(card.content.overrides.outboundCommunications.formatter).toBe('outboundMessages')
	})
})

describe('the receipt confirmation card footnote', () => {
	it('says how to record a confirmation sent outside dossiq, in Dutch too', () => {
		const card = findWidget(layout, 'case-acknowledgement-card')
		const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		expect(card.content.footnote).toBe('If the confirmation went outside dossiq, record it with Record receipt confirmed, under More.')
		expect(nl[card.content.footnote]).toBe('Ging de bevestiging buiten dossiq om, dan legt u hem vast met Ontvangst bevestigd vastleggen, onder Meer.')
	})
})
