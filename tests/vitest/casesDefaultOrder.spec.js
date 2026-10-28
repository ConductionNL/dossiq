// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The cases list says it is "sorted by deadline", so it orders by deadline.
 *
 * The simple profile's Cases page claimed the order in its count line but sent
 * no `_order`: the rows came in creation order and no header carried
 * `aria-sort`. `defaultOrder` names the deadline column, which the library
 * turns into the first fetch's `_order` and the table's sorted header.
 *
 * @spec openspec/changes/cases-default-order/specs/cases-default-order/spec.md#requirement-the-cases-list-opens-sorted-by-deadline
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const cases = layout.pages.find((page) => page.id === 'Cases')

describe('Cases page order', () => {
	it('opens sorted by deadline, as its count line says', () => {
		expect(cases.config.countText).toMatch(/sorted by deadline/)
		expect(cases.config.defaultOrder).toBe('deadline,receivedAt')
	})

	it('orders by a column it shows, so the header is marked sorted', () => {
		expect(cases.config.columns.map((column) => column.key)).toContain(cases.config.defaultOrder.split(',')[0].replace(/^-/, ''))
	})
})
