// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Files list's Visible to column (board DqDocumentenBeheer): everyone on
 * the case, unless the document carries an access exception, which then
 * names its people and groups.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? ''),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
	getLanguage: () => 'en',
	getLocale: () => 'en',
}))

const { default: formatters } = await import('../../src/services/formatters.js')
const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, '../../src/manifest.json'), 'utf8'))

describe('the Visible to column', () => {
	it('reads everyone on the case without an exception, and names the exception with one', () => {
		expect(formatters.visibleTo(undefined)).toBe('Everyone on the case')
		expect(formatters.visibleTo([])).toBe('Everyone on the case')
		expect(formatters.visibleTo(['Pieter Jansen', 'team Vergunningen'])).toBe('Only Pieter Jansen and team Vergunningen')
	})

	it('reads the access exception of the row', () => {
		const column = JSON.stringify(manifest).match(/\{"id":"visible-to"[^}]*\}/)
		expect(column).not.toBeNull()
		const parsed = JSON.parse(column[0])
		expect(parsed.key).toBe('accessExceptionNames')
		expect(parsed.formatter).toBe('visibleTo')
	})
})
