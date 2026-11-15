// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The anonymised export of a case list. The server anonymises; this pins
 * that the bulk action asks it, saves its answer and never falls back to a
 * plain file when it refuses.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
const showError = vi.fn()
const showSuccess = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args) } }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => showError(...args),
	showSuccess: (...args) => showSuccess(...args),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/index.php${url}` }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text) => text,
	translatePlural: (_app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
}))

const {
	ANONYMISED_EXPORT_URL,
	anonymisedCsv,
	csvCell,
	exportCasesAnonymised,
} = await import('../../src/utils/anonymisedExport.js')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

describe('the anonymised CSV', () => {
	it('quotes what needs quoting and writes nested values as JSON', () => {
		expect(csvCell('plain')).toBe('plain')
		expect(csvCell('a, "b"')).toBe('"a, ""b"""')
		expect(csvCell({ area: '1012' })).toBe('"{""area"":""1012""}"')
		expect(csvCell(null)).toBe('')
		expect(csvCell(1987)).toBe('1987')
	})

	it('has one column per field any case carries, in first-seen order', () => {
		expect(anonymisedCsv([
			{ title: 'Wmo', birthYear: 1950 },
			{ title: 'Jeugd', postcodeArea: '1012' },
		])).toBe('title,birthYear,postcodeArea\r\nWmo,1950,\r\nJeugd,,1012\r\n')
		expect(anonymisedCsv([])).toBe('')
		expect(anonymisedCsv(null)).toBe('')
	})
})

describe('exporting the selection anonymised', () => {
	beforeEach(() => {
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
		globalThis.URL.createObjectURL = vi.fn(() => 'blob:x')
		globalThis.URL.revokeObjectURL = vi.fn()
	})

	it('asks the server for the selected cases and saves its answer', async () => {
		post.mockResolvedValue({ data: { cases: [{ title: 'Wmo' }, { title: 'Jeugd' }] } })
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

		await expect(exportCasesAnonymised(['c1', 'c2'])).resolves.toBe(true)

		expect(post).toHaveBeenCalledWith(`/index.php${ANONYMISED_EXPORT_URL}`, { caseIds: ['c1', 'c2'] })
		expect(click).toHaveBeenCalledTimes(1)
		expect(showSuccess).toHaveBeenCalledWith('Exported 2 cases anonymised')
		click.mockRestore()
	})

	it('shows the refusal and saves nothing', async () => {
		post.mockRejectedValue({ response: { data: { error: 'A case type could not be read' } } })
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

		await expect(exportCasesAnonymised(['c1'])).resolves.toBe(false)

		expect(showError).toHaveBeenCalledWith('A case type could not be read')
		expect(click).not.toHaveBeenCalled()
		click.mockRestore()
	})

	it('does nothing without a selection, and says so when nothing came back', async () => {
		await expect(exportCasesAnonymised([])).resolves.toBe(false)
		expect(post).not.toHaveBeenCalled()

		post.mockResolvedValue({ data: { cases: [] } })
		await expect(exportCasesAnonymised(['c1'])).resolves.toBe(false)
		expect(showError).toHaveBeenCalledWith('None of the selected cases could be exported')
	})

	it('is a bulk action of the Cases list', () => {
		const cases = manifest.pages.find((entry) => entry.id === 'Cases').config
		expect(cases.bulkActions).toContainEqual(expect.objectContaining({ id: 'export-anonymised', handler: 'exportAnonymisedSelection' }))
		expect(registrySource).toMatch(/exportAnonymisedSelection: \{\n\t\tkind: 'handler',\n\t\thandler: exportAnonymisedSelection,/)
	})
})
