/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqWerkbord: a card's owner reads as the handler's name, "PJ" for Pieter
 * Jansen; :8080 drew "P" from the user id "pieter" (r29). The board card reads
 * the assignee through nextcloud-vue's built-in userName formatter.
 *
 * @spec openspec/specs/case-search-and-lists/spec.md#requirement-a-board-cards-owner-reads-as-the-handlers-name
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const card = manifest.pages.find((page) => page.id === 'Cases').config.board.card

describe('the Werkbord card owner', () => {
	it('reads the assignee as the handler\'s name', () => {
		expect(card.owner).toBe('assignee')
		expect(card.formatters.assignee).toBe('userName')
	})
})
