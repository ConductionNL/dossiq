/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * With nothing to list, the StUF endpoints and audit log draw the board's
 * empty state (an icon and a sentence), not a table with an empty row
 * (r33 DqBeheerGeavanceerd). Read as text: the panels fetch on mount.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (name) => readFileSync(resolve(__dirname, '../../src/views/settings', name), 'utf8')

describe('the StUF panels when empty', () => {
	it.each([
		['StufEndpoints.vue', 'endpoints', 'stuf-endpoints-empty'],
		['StufAuditLog.vue', 'messages', 'stuf-audit-log-empty'],
	])('%s draws the table only with rows and an empty state otherwise', (file, list, testid) => {
		const src = read(file)
		expect(src).toContain(`<table v-if="${list}.length"`)
		expect(src).toMatch(/<NcEmptyContent\s+v-else/)
		expect(src).toContain(`data-testid="${testid}"`)
		expect(src).not.toContain('colspan="8"')
	})
})
