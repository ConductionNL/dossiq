/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZakenSorteren: the side panel's "Sorteren op" offers Termijn first, then
 * Zaak, Behandelaar and Laatst gewijzigd, so the list's own deadline order is
 * a choice it can name. The urgency and newest orders stay.
 *
 * @spec openspec/specs/case-search-and-lists/spec.md#requirement-the-case-list-offers-the-orders-the-board-draws
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const options = manifest.pages.find((page) => page.id === 'Cases').config.sortSelectOptions

describe('DqZakenSorteren', () => {
	it('offers the deadline first, then case, handler and last changed, and keeps urgency and newest', () => {
		expect(options.map((option) => option.value)).toEqual(['deadline', 'title', 'assignee', 'updated', 'urgency', 'newest'])
	})

	it('sorts each by its own field', () => {
		const order = Object.fromEntries(options.map((option) => [option.value, option.order]))
		expect(order).toMatchObject({ deadline: 'deadline,receivedAt', title: 'title', assignee: 'assignee', updated: '-@self.updated' })
	})

	it('names each in Dutch as the board does', () => {
		expect(options.slice(0, 4).map((option) => nl[option.label])).toEqual(['Termijn', 'Zaak', 'Behandelaar', 'Laatst gewijzigd'])
	})
})
