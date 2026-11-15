/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContact "Tickets": a pipelinq ticket that was turned into a case has the
 * status `converted`; the ticket lists name it in words, as a handled state,
 * instead of printing the raw code.
 *
 * @spec openspec/changes/ticket-converted-label/specs/contact-tickets/spec.md#requirement-a-converted-ticket-is-named-in-words
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

/**
 * Every ticket status pill (one with the pipelinq `resolved` label).
 *
 * @param {*} node A manifest node.
 * @param {Array} out The pills found.
 * @return {Array} The pills.
 */
function ticketPills(node, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => ticketPills(child, out))
	} else if (node && typeof node === 'object') {
		if (node.field === 'status' && node.labels && node.labels.awaiting_customer) {
			out.push(node)
		}
		Object.values(node).forEach((child) => ticketPills(child, out))
	}
	return out
}

describe('a converted ticket', () => {
	const pills = ticketPills(manifest)

	it('is found in every ticket list', () => {
		expect(pills.length).toBeGreaterThanOrEqual(3)
	})

	it('is named in words and toned as handled', () => {
		for (const pill of pills) {
			expect(pill.labels.converted).toBe('Converted to a case')
			expect(pill.colorMap.converted).toBe('success')
		}
		expect(nl['Converted to a case']).toBe('Omgezet in een zaak')
	})
})
