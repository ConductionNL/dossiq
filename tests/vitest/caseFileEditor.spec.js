/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Opening a case file in the editor from the case Files tab.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))

const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
const mockError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showError: (...a) => mockError(...a) }))

const { openCaseFileInEditor } = await import('../../src/utils/caseFileEditor.js')

const CASE = 'd0d1386f-8bc6-4759-b5c6-33f552faf2bd'
let assign

beforeEach(() => {
	mockPost.mockReset()
	mockError.mockReset()
	assign = vi.fn()
	vi.stubGlobal('window', { location: { pathname: `/index.php/apps/dossiq/cases/${CASE}`, assign } })
})

describe('openCaseFileInEditor', () => {
	it('asks the server for the case file and follows the link it answers', async () => {
		mockPost.mockResolvedValue({ data: { url: '/index.php/f/42' } })

		await openCaseFileInEditor({}, { fileid: 42, basename: 'report.docx' })

		expect(mockPost).toHaveBeenCalledWith(`/apps/dossiq/api/cases/${CASE}/files/42/editor`)
		expect(assign).toHaveBeenCalledWith('/index.php/f/42')
		expect(mockError).not.toHaveBeenCalled()
	})

	it('shows the server refusal and stays on the case', async () => {
		mockPost.mockRejectedValue({ response: { data: { error: 'You may not change this case, so its files cannot be edited.' } } })

		await openCaseFileInEditor({ fileid: 42 })

		expect(assign).not.toHaveBeenCalled()
		expect(mockError).toHaveBeenCalledWith('You may not change this case, so its files cannot be edited.')
	})

	it('asks nothing for a row without a file id', async () => {
		await openCaseFileInEditor({ basename: 'x' })

		expect(mockPost).not.toHaveBeenCalled()
		expect(mockError).toHaveBeenCalled()
	})

	it('is the first row action of the case Files tab', () => {
		const text = JSON.stringify(manifest)
		const at = text.indexOf('"id":"open-in-editor"')
		expect(at).toBeGreaterThan(-1)
		expect(text.indexOf('"id":"document-properties"')).toBeGreaterThan(at)
	})
})
