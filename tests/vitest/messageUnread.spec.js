// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Mark a single message read or unread (unread-state-on-the-case 1.2b).
 *
 * A dossiq message is a `contactmoment` object, so the gesture is the case's
 * read-state write on another schema. Asserted here: the three message lists
 * offer both marks, the handler names resolve in the registry and their icons
 * are registered, and the writes go to OpenRegister's read state of the
 * message itself and nowhere else.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { markMessageRead, markMessageUnread } from '../../src/utils/messageUnread.js'

const mockShowSuccess = vi.fn()
const mockShowError = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockShowSuccess(...a),
	showError: (...a) => mockShowError(...a),
}))

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, 'src', file), 'utf8'))
const manifest = read('manifest.json')
const simpleLayout = read('menu-layout.simple.json')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

/**
 * Every object-list widget over contactmoment with the given id, anywhere in the manifest.
 *
 * @param {unknown} node The tree.
 * @param {string} id The widget id.
 * @param {Array} found The accumulator.
 * @return {Array} The widgets.
 */
function messageLists(node, id, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => messageLists(child, id, found))
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type === 'object-list' && node.content?.schema === 'contactmoment') {
			found.push(node)
		}
		Object.values(node).forEach((child) => messageLists(child, id, found))
	}
	return found
}

const URL = '/index.php/apps/openregister/api/objects/dossiq/contactmoment/msg-3/read-state'

describe('the message lists offer both marks', () => {
	it.each([
		['manifest.json', 'case-communication', 1],
		['manifest.json', 'contact-moments', 2],
		['menu-layout.simple.json', 'case-contact-moments-card', 1],
	])('%s %s', (file, id, count) => {
		const lists = messageLists(file === 'manifest.json' ? manifest : simpleLayout, id)
		expect(lists).toHaveLength(count)
		for (const list of lists) {
			const byId = Object.fromEntries(list.content.rowActions.map((a) => [a.id, a]))
			expect(byId['mark-unread']).toMatchObject({ type: 'handler', handler: 'markMessageUnread', icon: 'EmailMarkAsUnread' })
			expect(byId['mark-read']).toMatchObject({ type: 'handler', handler: 'markMessageRead', icon: 'EmailOpenOutline' })
		}
	})

	it('names registered icons and registered handlers', () => {
		for (const icon of ['EmailMarkAsUnread', 'EmailOpenOutline']) {
			expect(iconsSource).toMatch(new RegExp(`\\n\\t${icon},\\n`))
		}
		expect(registrySource).toContain("import { markMessageRead, markMessageUnread } from './utils/messageUnread.js'")
		expect(registrySource).toMatch(/\n\tmarkMessageRead: \{\n\t\tkind: 'handler',\n\t\thandler: markMessageRead,\n/)
		expect(registrySource).toMatch(/\n\tmarkMessageUnread: \{\n\t\tkind: 'handler',\n\t\thandler: markMessageUnread,\n/)
	})
})

describe('the handlers write the message read state and nowhere else', () => {
	beforeEach(() => {
		axios.put.mockReset()
		axios.delete.mockReset()
		mockShowSuccess.mockReset()
		mockShowError.mockReset()
	})

	it('puts a message back to unread with a DELETE on its own read state', async () => {
		axios.delete.mockResolvedValue({ data: { unread: true } })

		await markMessageUnread({ actionId: 'mark-unread', item: { id: 'msg-3' } })

		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(axios.delete.mock.calls[0][0]).toBe(URL)
		expect(mockShowSuccess).toHaveBeenCalledWith('You will see this message as unread again.')
	})

	it('marks a message read with a PUT carrying no sub-resource', async () => {
		axios.put.mockResolvedValue({ data: { unread: false, notificationsCleared: 1 } })

		await markMessageRead({ id: 'msg-3' })

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put.mock.calls[0][0]).toBe(URL)
		expect(axios.put.mock.calls[0][1]).toEqual({})
		expect(mockShowSuccess).toHaveBeenCalledWith('You have marked this message read.')
	})

	it('reads the id off the metadata when the row carries no plain one', async () => {
		axios.delete.mockResolvedValue({ data: { unread: true } })

		await markMessageUnread({ item: { '@self': { id: 'msg-3' } } })

		expect(axios.delete.mock.calls[0][0]).toBe(URL)
	})

	it('writes nothing for a row with no id', async () => {
		await markMessageUnread({ item: {} })
		await markMessageRead(null)

		expect(axios.delete).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('shows the refusal OpenRegister gave', async () => {
		axios.put.mockRejectedValue({ response: { data: { message: 'Not allowed' } } })

		await markMessageRead({ item: { id: 'msg-3' } })

		expect(mockShowError).toHaveBeenCalledWith('Not allowed')
		expect(mockShowSuccess).not.toHaveBeenCalled()
	})
})
