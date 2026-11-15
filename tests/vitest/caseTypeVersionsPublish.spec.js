/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypeOverzicht, Versies: on a draft the versions card ends with
 * the case type's existing Publish action; a published version offers a new
 * version instead.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import layout from '../../src/menu-layout.simple.json'

const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
const card = page.config.sideColumn.find((c) => c.id === 'side-case-type-versions')

describe('the versions card of a case type', () => {
	it('opens the same publish dialog as the header action', () => {
		const header = manifest.pages
			.find((p) => p.id === 'CaseTypeDetail')
			.config.headerActions.find((a) => a.id === 'case-type-publish')
		expect(card.content.footerAction[0]).toMatchObject({ type: header.type, target: header.target, label: 'Publish' })
	})

	it('offers Publish on a draft only', () => {
		expect(card.content.footerAction[0].visibleWhen).toEqual({ field: 'isDraft', op: 'eq', value: true })
		expect(card.content.footerAction[1].label).toBe('Make a new version')
	})
})
