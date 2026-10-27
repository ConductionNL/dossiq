/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dashboards whose boards space their cards 20px apart draw them with a
 * 10px grid margin (GridStack's default 12 drew 24px gaps and 357px cards in a
 * row of three; DqDashboard, DqDagAfsluiten, DqDoorlooptijd, DqProcesanalyse,
 * DqTermijnen draw 20px gaps and 360px cards). My work keeps 12: its board
 * spaces its cards 24px.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const page = (id) => manifest.pages.find((item) => item.id === id)

describe('dashboard grid margins', () => {
	it('spaces the cards 20px on the boards that draw 20px', () => {
		for (const id of ['Dashboard', 'EndOfDay', 'Doorlooptijd', 'ProcessMiningDashboard', 'TermijnDashboard']) {
			expect(page(id).config.gridMargin, id).toBe(10)
		}
	})

	it('keeps the default on My work', () => {
		expect(page('MyWorkHome').config.gridMargin).toBeUndefined()
	})
})
