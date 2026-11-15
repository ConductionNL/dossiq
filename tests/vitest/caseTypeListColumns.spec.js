/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypen: the name column takes what the four narrow columns
 * leave, so a case type's name and line are not cut off, and the footer says
 * the page size.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const config = layout.pages.find((p) => p.id === 'CaseTypes').config

describe('the case type list', () => {
	it('fixes the narrow columns and leaves the name free', () => {
		const width = Object.fromEntries(config.columns.map((c) => [c.key, c.width]))
		expect(width).toEqual({ title: undefined, category: '149px', processingDeadline: '158px', version: '115px', isDraft: '130px' })
	})

	it('names the page size in the footer', () => {
		expect(config.footerNote).toBe('20 per page')
	})
})
