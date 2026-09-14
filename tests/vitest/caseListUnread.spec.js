// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The unread lens, the unread column and both marks on the case lists.
 *
 * Every assertion here guards a way this feature could ship DARK or, worse,
 * ship WRONG while reading green:
 *
 *  - `_unread` is a LENS resolved inside OpenRegister's query, not a field
 *    filter. Spelled `filter[_unread]` or nested it would be read as a
 *    property nothing matches, and the chip would answer an empty list on
 *    every instance with no error anywhere. The flat key with a boolean true
 *    is what survives resolveFilterMap, useListView's fixed-filter merge and
 *    buildQueryString, in that order, and reaches the server as
 *    `_unread=true`;
 *  - a `false` filter value is dropped by the LEGACY list path, so the chip
 *    carries `true` and never `{_unread: false}`;
 *  - an index row action knows only `navigate`, `open-page` and a handler
 *    NAME out of customComponents, so both marks are asserted to resolve to
 *    functions that are actually exported;
 *  - an icon that is not registered in `src/icons.js` renders no glyph at all
 *    rather than a fallback, so all three new names are asserted registered;
 *  - the column is `sortable: false` because `@self.unread` is attached on the
 *    render path and no column exists to order by. A sortable header would
 *    send an `_order` the mapper answers by ignoring, which reads as a broken
 *    sort rather than as an absent one.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { isUnread, unreadCountsOf } from '../../src/services/readStateApi.js'
import { markCaseRead, markCaseUnread } from '../../src/utils/caseUnread.js'

const mockShowSuccess = vi.fn()
const mockShowError = vi.fn()

// `@nextcloud/axios`, `@nextcloud/router` and `@nextcloud/l10n` are aliased to
// the suite's own stubs in vitest.config.js; only the toasts need a mock here.
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockShowSuccess(...a),
	showError: (...a) => mockShowError(...a),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const registrySource = fs.readFileSync(
	path.join(ROOT, 'src', 'customComponents.js'),
	'utf8',
)
const cellWidgetsSource = fs.readFileSync(
	path.join(ROOT, 'src', 'services', 'cellWidgets.js'),
	'utf8',
)

/**
 * One page out of the manifest, by id.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	const found = manifest.pages.find((p) => p.id === id)
	expect(found, `page ${id} is missing from the manifest`).toBeTruthy()
	return found
}

/**
 * One action out of a list, by id.
 *
 * @param {Array} list The actions.
 * @param {string} id The action id.
 * @return {object} The action.
 */
function action(list, id) {
	const found = (list || []).find((a) => a.id === id)
	expect(found, `action ${id} is missing`).toBeTruthy()
	return found
}

/**
 * The unread column of one index page.
 *
 * @param {string} id The page id.
 * @return {object} The column entry.
 */
function unreadColumn(id) {
	const found = page(id).config.columns.find(
		(c) => typeof c === 'object' && c.key === '@self.unread',
	)
	expect(found, `${id} carries no unread column`).toBeTruthy()
	return found
}

describe('the Unread lens on the Cases index', () => {
	const chips = page('Cases').config.quickFilters

	it('declares one Unread chip, right after All', () => {
		const labels = chips.map((c) => c.label)
		expect(labels.filter((l) => l === 'Unread')).toHaveLength(1)
		expect(labels[0]).toBe('All')
		expect(labels[1]).toBe('Unread')
	})

	it('asks for the lens with a flat boolean key and the hidden narrowing', () => {
		// The whole filter, asserted key for key. It carried `_unread` alone
		// until REQ-LIFE-01; `statusHiddenInLists: false` is the ONE condition
		// allowed beside it, and it is allowed because a status an
		// administrator hid is hidden on every working lens or on none. What
		// this test still refuses is a third condition that would narrow "what
		// moved overnight" to a slice of it, which is the question the chip
		// exists to answer.
		expect(chips.find((c) => c.label === 'Unread').filter).toEqual({
			_unread: true,
			statusHiddenInLists: false,
		})
	})

	it('does not mark Unread as the chip the page opens on', () => {
		// A `default` chip is activated ON MOUNT, so an Unread default would
		// make a handler's first paint of the case list the three rows that
		// moved, and an empty result would read as an empty register.
		const defaults = chips.filter((c) => c.default === true)
		expect(defaults).toHaveLength(1)
		expect(defaults[0].label).toBe('All')
	})

	it('leaves the Queue page without a chip of its own', () => {
		// The Queue's base filter IS the page. A chip narrowing it further
		// would leave a reader unable to tell an empty queue from an empty
		// lens, and the base filter is what makes the queue trustworthy.
		expect(page('Queue').config.quickFilters).toBeUndefined()
	})
})

describe('the Unread column on both case lists', () => {
	it('sits first on Cases and on Queue', () => {
		for (const id of ['Cases', 'Queue']) {
			const first = page(id).config.columns[0]
			expect(typeof first).toBe('object')
			expect(first.key).toBe('@self.unread')
		}
	})

	it('renders through a cell widget that is registered', () => {
		for (const id of ['Cases', 'Queue']) {
			expect(unreadColumn(id).widget).toBe('unreadIndicator')
		}
		expect(cellWidgetsSource).toMatch(/\n\tunreadIndicator: UnreadIndicatorCell,\n/)
		expect(cellWidgetsSource).toContain(
			"import UnreadIndicatorCell from '../components/cells/UnreadIndicatorCell.vue'",
		)
	})

	it('is not sortable, because there is no column to sort on', () => {
		for (const id of ['Cases', 'Queue']) {
			expect(unreadColumn(id).sortable).toBe(false)
		}
	})
})

describe('an absent unread flag reads as read', () => {
	it('is unread only when the row says so in so many words', () => {
		expect(isUnread({ '@self': { unread: true } })).toBe(true)
		expect(isUnread({ '@self': { unread: false } })).toBe(false)
	})

	it('reads an anonymous or unattached row as read', () => {
		// `@self.unread` is OMITTED ENTIRELY for an anonymous read, where there
		// is no "you" to answer for. Treating absence as unread would paint
		// every row on a public page.
		expect(isUnread({ '@self': {} })).toBe(false)
		expect(isUnread({})).toBe(false)
		expect(isUnread(null)).toBe(false)
		expect(isUnread({ '@self': { unread: 'true' } })).toBe(false)
	})

	it('answers null rather than an empty map when a row carries no counts', () => {
		// A row with no counts and a row whose every tab is read are different
		// claims, and a caller that cannot tell them apart renders "nothing
		// new" for a case it never asked about.
		expect(unreadCountsOf({ '@self': { unreadCounts: { files: 2 } } })).toEqual({ files: 2 })
		expect(unreadCountsOf({ '@self': {} })).toBeNull()
		expect(unreadCountsOf({})).toBeNull()
	})
})

describe('both marks are offered on a row', () => {
	it('declares them on Cases and on Queue', () => {
		for (const id of ['Cases', 'Queue']) {
			const unread = action(page(id).config.actions, 'mark-unread')
			expect(unread.type).toBe('handler')
			expect(unread.handler).toBe('markCaseUnread')
			expect(unread.label).toBe('Mark unread')

			const read = action(page(id).config.actions, 'mark-read')
			expect(read.type).toBe('handler')
			expect(read.handler).toBe('markCaseRead')
			expect(read.label).toBe('Mark read')
		}
	})

	it('names icons that are registered', () => {
		for (const id of ['Cases', 'Queue']) {
			for (const actionId of ['mark-unread', 'mark-read']) {
				const icon = action(page(id).config.actions, actionId).icon
				expect(iconsSource, `${icon} is not registered in src/icons.js`).toContain(
					`\n\t${icon},\n`,
				)
			}
		}
	})

	it('resolves both handlers to functions in the custom-component registry', () => {
		// Asserted against the SOURCE rather than by importing the module:
		// customComponents.js imports every surviving page and tab, so a unit
		// test that imported it would mount the component tree to reach two
		// functions.
		expect(registrySource).toContain(
			"import { markCaseRead, markCaseUnread } from './utils/caseUnread.js'",
		)
		expect(registrySource).toMatch(/\n\tmarkCaseRead,\n/)
		expect(registrySource).toMatch(/\n\tmarkCaseUnread,\n/)
		expect(typeof markCaseRead).toBe('function')
		expect(typeof markCaseUnread).toBe('function')
	})
})

describe('the row handlers write to OpenRegister and nowhere else', () => {
	beforeEach(() => {
		axios.put.mockReset()
		axios.delete.mockReset()
		mockShowSuccess.mockReset()
		mockShowError.mockReset()
	})

	it('puts a case back to unread with a DELETE on the read state', async () => {
		axios.delete.mockResolvedValue({ data: { unread: true } })

		await markCaseUnread({ actionId: 'mark-unread', item: { id: 'case-7' } })

		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(axios.delete.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/objects/dossiq/case/case-7/read-state',
		)
		expect(mockShowSuccess).toHaveBeenCalledWith('You will see this as unread again.')
	})

	it('marks a case read with a PUT carrying no sub-resource', async () => {
		// No sub-resource on purpose: OpenRegister stamps only the one a PUT
		// names, so marking the case read from a row must not silently stamp
		// its panels as well and take the Documents badge with it.
		axios.put.mockResolvedValue({ data: { unread: false, notificationsCleared: 2 } })

		await markCaseRead({ actionId: 'mark-read', item: { id: 'case-7' } })

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/objects/dossiq/case/case-7/read-state',
		)
		expect(axios.put.mock.calls[0][1]).toEqual({})
	})

	it('reads the id off the metadata when the row carries no plain one', async () => {
		axios.delete.mockResolvedValue({ data: { unread: true } })

		await markCaseUnread({ actionId: 'mark-unread', item: { '@self': { id: 'case-9' } } })

		expect(axios.delete.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/objects/dossiq/case/case-9/read-state',
		)
	})

	it('writes nothing at all for a row with no id', async () => {
		await markCaseUnread({ actionId: 'mark-unread', item: {} })
		await markCaseRead({ actionId: 'mark-read', item: {} })

		expect(axios.delete).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('shows the refusal the server wrote, unchanged', async () => {
		axios.delete.mockRejectedValue({
			response: { status: 403, data: { message: 'A read state is your own.' } },
		})

		await markCaseUnread({ actionId: 'mark-unread', item: { id: 'case-7' } })

		expect(mockShowError).toHaveBeenCalledWith('A read state is your own.')
	})
})
