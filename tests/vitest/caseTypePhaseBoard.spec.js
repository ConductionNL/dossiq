/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktype, Fasen en taken: the tab opens with the case type's phases,
 * each with the tasks its workflow runs, read from the phases endpoint (the
 * steps are a JSON string no declarative list can group), then the terms,
 * the messages and the blueprint as before, so nothing that tab showed is lost.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
const widgets = page.configAppend.widgets
const tabs = widgets.find((w) => w.type === 'tabs').content.tabs

describe('the Phases and tasks tab of a case type', () => {
	it('opens with the phase board and draws the board\'s four cards', () => {
		const tab = tabs.find((t) => t.label === 'Phases and tasks')
		expect(tab.widgetIds).toEqual(['case-type-phase-board', 'case-type-variants', 'case-type-terms-written', 'case-type-messages'])
	})

	it('reads the phases of this case type from the phases endpoint', () => {
		const board = widgets.find((w) => w.id === 'case-type-phase-board')
		expect(board.type).toBe('grouped-cards')
		expect(board.content.endpointSource.url).toBe('/apps/dossiq/api/case-types/@objectId/phases')
		expect(board.content.groupsPath).toBe('phases')
		expect(board.content.itemsKey).toBe('tasks')
		expect(board.content.extraGroup.path).toBe('alwaysAvailable')
	})
})
