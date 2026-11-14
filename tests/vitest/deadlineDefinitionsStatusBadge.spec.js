/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An AWB term definition's state is the library's status badge in its tone,
 * so it takes the theme's tag colours as the board draws them (r33: "Actief"
 * was a local colour mix). Read as text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const tab = readFileSync(resolve(__dirname, '../../src/views/settings/tabs/DeadlineDefinitionsTab.vue'), 'utf8')

describe('the AWB term definitions state', () => {
	it('is the status badge, success when active and neutral otherwise', () => {
		expect(tab).toContain(`:class="isActive(def) ? 'cn-status-badge--success' : 'cn-status-badge--default'"`)
		expect(tab).not.toContain('deadline-definitions-tab__badge')
	})
})
