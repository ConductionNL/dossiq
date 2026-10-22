// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakContact draws "Gesprek starten" and "Groot incident melden" in the
 * live conversation card's title row. Both buttons run the one start and the
 * one declaration the live-conversation panel runs (utils/caseConversation.js),
 * registered as handlers the card header dispatches; the panel then leaves
 * its own two buttons out.
 */

import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
const emitted = vi.fn()
const shown = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args), get: vi.fn() } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => emitted(...args), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: (...args) => shown(...args), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params = {}) => url.replace('{caseId}', params.caseId) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m) }))

const { declareCaseMajor, startCaseConversation } = await import('../../src/utils/caseConversation.js')

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

describe('the live conversation buttons in the card header', () => {
	beforeEach(() => {
		window.history.pushState({}, '', '/index.php/apps/dossiq/cases/case-1')
		window.open = vi.fn()
		post.mockReset()
		emitted.mockReset()
		shown.mockReset()
	})

	afterEach(() => {
		window.history.pushState({}, '', '/')
	})

	it('starts a conversation on the open case, opens the room and lets the page read the case again', async () => {
		post.mockResolvedValue({ data: { conversation: { roomUrl: 'https://talk/x' } } })
		await startCaseConversation()
		expect(post).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/conversations', {})
		expect(window.open).toHaveBeenCalledWith('https://talk/x', '_blank', 'noopener')
		expect(emitted).toHaveBeenCalledWith('cn:page:refresh')
	})

	it('declares the open case major and says which responders it could not find', async () => {
		post.mockRejectedValue({ response: { data: { unresolved: ['Team brandweer'] } } })
		await declareCaseMajor()
		expect(post).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/major', {})
		expect(shown).toHaveBeenCalledWith('We could not find these responders: Team brandweer')
		expect(emitted).not.toHaveBeenCalled()
	})

	it('are registered handlers, placed on the live conversation card, primary last', () => {
		const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		expect(registry).toMatch(/startCaseConversation: \{\s*kind: 'handler'/)
		expect(registry).toMatch(/declareCaseMajor: \{\s*kind: 'handler'/)
		const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
		const card = simple.configAppend.widgets.find((widget) => widget.id === 'case-conversations-card')
		expect(card.content.actionsInHeader).toBe(true)
		expect(card.headerActions.map((entry) => entry.action.handler)).toEqual(['declareCaseMajor', 'startCaseConversation'])
		expect(card.headerActions[1].variant).toBe('primary')
		const nl = read('l10n/nl.json').translations
		expect(card.headerActions.map((entry) => nl[entry.label])).toEqual(['Groot incident melden', 'Gesprek starten'])
	})

	it('one implementation: the panel posts through the same module', () => {
		const panel = fs.readFileSync(path.join(ROOT, 'src/views/cases/components/CaseConversationsPanel.vue'), 'utf8')
		expect(panel).toContain("from '../../../utils/caseConversation.js'")
		expect(panel).not.toContain('/api/cases/{caseId}/major')
		expect(panel).not.toContain('/api/cases/{caseId}/conversations')
	})
})
