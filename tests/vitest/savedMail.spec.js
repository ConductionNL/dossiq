/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reading a saved `.eml` or `.msg` on a case as the message it is.
 *
 * The branch this file exists for is the one that would otherwise lie: the
 * endpoint answers 200 with `outcome: kept` when integriq is absent or the
 * file could not be read, because both are real answers to what the handler
 * asked, and a success toast over one of them would say the case now holds a
 * message it does not.
 *
 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
const mockSuccess = vi.fn()
const mockError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockSuccess(...a),
	showError: (...a) => mockError(...a),
}))
const mockEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))

const { looksLikeMail, readFileAsMessage } = await import(
	'../../src/utils/savedMail.js'
)

beforeEach(() => {
	mockPost.mockReset()
	mockSuccess.mockReset()
	mockError.mockReset()
	mockEmit.mockReset()
	vi.stubGlobal('window', {
		location: { pathname: '/apps/dossiq/cases/case-1' },
	})
})

describe('the Files tab offers Read as a message', () => {
	it('declares the row action as a function handler', () => {
		const page = manifest.pages.find((p) => p.id === 'CaseDetail')
		const files = page.config.widgets.find((w) => w.id === 'case-files')
		const action = files.props.rowActions.find((a) => a.id === 'read-as-message')

		expect(action).toBeDefined()
		// 🔴 `handler`, not `api-call`. CnFilesBrowser's row-action vocabulary
		// is `open-modal` and `handler`; a declared POST would render a menu
		// item that does nothing when clicked.
		expect(action.type).toBe('handler')
		expect(action.handler).toBe('readFileAsMessage')
	})
})

describe('looksLikeMail', () => {
	it.each([
		['bezwaar.eml', true],
		['bezwaar.msg', true],
		['BEZWAAR.MSG', true],
		['archief.mbox', true],
		['foto.jpg', false],
		['', false],
	])('%s -> %s', (name, expected) => {
		expect(looksLikeMail({ basename: name })).toBe(expected)
	})
})

describe('readFileAsMessage', () => {
	it('files the parsed message and refreshes the page', async () => {
		mockPost.mockResolvedValue({
			data: { outcome: 'imported', subject: 'Bezwaar dakkapel' },
		})

		await readFileAsMessage({ fileid: 77, basename: 'bezwaar.msg' })

		expect(mockPost).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/case-1/files/77/read-as-message',
		)
		expect(mockSuccess).toHaveBeenCalled()
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh')
	})

	it('shows the reason and no success when the file was only kept', async () => {
		// The endpoint answers 200 for this: a missing integriq is a real
		// answer, not a server error.
		mockPost.mockResolvedValue({
			data: {
				outcome: 'kept',
				reason: 'Integriq is not installed, and it is what reads a saved mail file.',
			},
		})

		await readFileAsMessage({ fileid: 77, basename: 'bezwaar.eml' })

		expect(mockSuccess).not.toHaveBeenCalled()
		expect(mockEmit).not.toHaveBeenCalled()
		expect(mockError).toHaveBeenCalledWith(
			'Integriq is not installed, and it is what reads a saved mail file.',
		)
	})

	it('refuses a row that is not a mail file, without asking the server', async () => {
		// CnFilesBrowser offers every row action on every file, so this check
		// cannot be a `visibleWhen`: it has to answer with a sentence.
		await readFileAsMessage({ fileid: 77, basename: 'foto.jpg' })

		expect(mockPost).not.toHaveBeenCalled()
		expect(mockError).toHaveBeenCalled()
	})

	it('does nothing at all when no file was clicked', async () => {
		await readFileAsMessage({})

		expect(mockPost).not.toHaveBeenCalled()
		expect(mockError).not.toHaveBeenCalled()
	})

	it('refuses when the page is not a case page', async () => {
		vi.stubGlobal('window', { location: { pathname: '/apps/files' } })

		await readFileAsMessage({ fileid: 77, basename: 'bezwaar.eml' })

		expect(mockPost).not.toHaveBeenCalled()
		expect(mockError).toHaveBeenCalled()
	})
})
