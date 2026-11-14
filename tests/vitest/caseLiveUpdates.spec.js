// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case page subscribes to the case it is showing (gap register row 2.20).
 *
 * `openspec/specs/realtime-updates-ui/spec.md` required this before the page
 * did it, which is the shape of failure this file is written against: a spec
 * that is satisfied by nothing and reads as satisfied. Every assertion below
 * is a way the subscription could be absent or wrong while the page looks
 * exactly the same:
 *
 *  - subscribing on any route with an `id`, which would point the CASE store
 *    at a workflow definition, a bezwaar or a tenant;
 *  - subscribing again for the case already held, which leaks one handle per
 *    navigation and refetches once per leak;
 *  - keeping the handle a late `subscribe()` resolved after the reader had
 *    already opened another case, which refetches for a page nobody is on
 *    until the tab closes;
 *  - a subscription that throws taking the page down with it;
 *  - `pollSeconds` DELETED rather than set to 0, which leaves the widget
 *    polling at its own default of 15 while the manifest says nothing.
 *
 * @spec openspec/changes/live-updates-on-the-case-page/specs/realtime-updates-ui/spec.md
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import {
	caseIdOfRoute,
	CaseLiveSubscription,
	installCaseLiveUpdates,
} from '../../src/services/caseLiveUpdates.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const mainSource = fs.readFileSync(path.join(ROOT, 'src', 'main.js'), 'utf8')

/** The CaseDetail page of the manifest. */
const casePage = manifest.pages.find((page) => page.id === 'CaseDetail')

/**
 * Every `pollSeconds` declared anywhere under a node.
 *
 * @param {*} node The node to walk.
 * @param {Array<number>} found The accumulator.
 *
 * @return {Array<number>} Every declared poll.
 */
function pollsUnder(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((entry) => pollsUnder(entry, found))
		return found
	}
	if (node === null || typeof node !== 'object') {
		return found
	}
	if (Object.hasOwn(node, 'pollSeconds')) {
		found.push(node.pollSeconds)
	}
	Object.values(node).forEach((value) => pollsUnder(value, found))
	return found
}

/**
 * A store double with just the surface the subscription touches.
 *
 * `subscribe` is a spy rather than a stub that adds methods the real store
 * lacks: the two calls this code makes, `subscribe` and `unsubscribe`, are
 * both actions `liveUpdatesPlugin` contributes, and nothing else is invented
 * here.
 *
 * @param {object} [options] Overrides.
 *
 * @return {object} The double.
 */
function storeDouble({ subscribe, registered = true } = {}) {
	const handles = []
	return {
		objectTypeRegistry: registered ? { case: { schema: 24 } } : {},
		subscribe:
			subscribe
			|| vi.fn(async (type, id) => {
				const handle = { type, id }
				handles.push(handle)
				return handle
			}),
		unsubscribe: vi.fn(),
		handles,
	}
}

describe('which route is a case page', () => {
	it('reads the case id off the case route', () => {
		expect(caseIdOfRoute({ name: 'CaseDetail', params: { id: 'c-1' } })).toBe(
			'c-1',
		)
	})

	it('answers nothing for another page that also carries an id', () => {
		// The failure this guards: `params.id` alone would subscribe the CASE
		// store to a workflow definition or a tenant, and the refetch would be
		// for an object that store cannot resolve.
		for (const name of [
			'WorkflowDefinitionDetail',
			'TenantDetail',
			'BezwaarDetail',
			'CaseTypeDetail',
		]) {
			expect(caseIdOfRoute({ name, params: { id: 'x-1' } })).toBe('')
		}
	})

	it('answers nothing for the case route with no id', () => {
		expect(caseIdOfRoute({ name: 'CaseDetail', params: {} })).toBe('')
		expect(caseIdOfRoute(null)).toBe('')
	})

	it('names a route the manifest actually declares', () => {
		// A subscription keyed on a page id nothing renders never fires, and
		// nothing says so.
		expect(casePage).toBeDefined()
		expect(casePage.route).toBe('/cases/:id')
	})
})

describe('one subscription, pointed at the case on screen', () => {
	it('subscribes to the case, by id', async () => {
		const store = storeDouble()
		const subscription = new CaseLiveSubscription(store)

		await subscription.sync('c-1')

		expect(store.subscribe).toHaveBeenCalledWith('case', 'c-1')
		expect(subscription.key).toBe('c-1')
	})

	it('does not subscribe twice to the case it already holds', async () => {
		const store = storeDouble()
		const subscription = new CaseLiveSubscription(store)

		await subscription.sync('c-1')
		await subscription.sync('c-1')
		await subscription.sync('c-1')

		expect(store.subscribe).toHaveBeenCalledTimes(1)
		expect(store.unsubscribe).not.toHaveBeenCalled()
	})

	it('releases the old case before subscribing to the next', async () => {
		const store = storeDouble()
		const subscription = new CaseLiveSubscription(store)

		await subscription.sync('c-1')
		await subscription.sync('c-2')

		expect(store.unsubscribe).toHaveBeenCalledTimes(1)
		expect(store.unsubscribe).toHaveBeenCalledWith({ type: 'case', id: 'c-1' })
		expect(store.subscribe).toHaveBeenLastCalledWith('case', 'c-2')
	})

	it('releases when the reader leaves the case page', async () => {
		const store = storeDouble()
		const subscription = new CaseLiveSubscription(store)

		await subscription.sync('c-1')
		await subscription.sync('')

		expect(store.unsubscribe).toHaveBeenCalledTimes(1)
		expect(subscription.handle).toBeNull()
	})

	it('drops a handle that resolved after the reader moved on', async () => {
		// The epoch guard. Without it the late handle is stored for a case
		// nobody is looking at and refetches until the tab closes, which is
		// invisible: the page it refreshes is not on screen.
		let releaseFirst = null
		const store = storeDouble({
			subscribe: vi.fn(
				(type, id) =>
					new Promise((resolve) => {
						if (id === 'slow') {
							releaseFirst = () => resolve({ type, id })
							return
						}
						resolve({ type, id })
					}),
			),
		})
		const subscription = new CaseLiveSubscription(store)

		const pending = subscription.sync('slow')
		await subscription.sync('c-2')
		releaseFirst()
		await pending

		expect(store.unsubscribe).toHaveBeenCalledWith({
			type: 'case',
			id: 'slow',
		})
		expect(subscription.handle).toEqual({ type: 'case', id: 'c-2' })
	})

	it('survives a subscription that throws, and records why', async () => {
		const store = storeDouble({
			subscribe: vi.fn(async () => {
				throw new Error('notify_push is unreachable')
			}),
		})
		const subscription = new CaseLiveSubscription(store)

		await expect(subscription.sync('c-1')).resolves.toBeUndefined()
		expect(subscription.handle).toBeNull()
		expect(subscription.lastError).toContain('notify_push')
	})

	it('waits rather than throwing when the case type is not registered yet', async () => {
		// `subscribe()` throws inside the plugin for an unregistered type, and
		// on a deep link the settings read has not landed when the first
		// navigation fires.
		const store = storeDouble({ registered: false })
		const subscription = new CaseLiveSubscription(store)

		await subscription.sync('c-1')

		expect(store.subscribe).not.toHaveBeenCalled()
		expect(subscription.handle).toBeNull()
	})

	it('does nothing at all on a store without the plugin', async () => {
		const subscription = new CaseLiveSubscription({ objectTypeRegistry: {} })

		await expect(subscription.sync('c-1')).resolves.toBeUndefined()
	})
})

describe('the router drives it', () => {
	it('syncs on every navigation, and resolves the store lazily', async () => {
		const hooks = []
		const router = { afterEach: (fn) => hooks.push(fn) }
		const store = storeDouble()
		const storeFactory = vi.fn(() => store)

		installCaseLiveUpdates(router, storeFactory)

		expect(hooks).toHaveLength(1)
		// Lazily: pinia is installed on the app AFTER the router is built, so
		// resolving at install time throws.
		expect(storeFactory).not.toHaveBeenCalled()

		hooks[0]({ name: 'CaseDetail', params: { id: 'c-1' } })
		await Promise.resolve()
		await Promise.resolve()

		expect(storeFactory).toHaveBeenCalledTimes(1)
		expect(store.subscribe).toHaveBeenCalledWith('case', 'c-1')
	})

	it('is wired into the app, or it is a module nothing calls', () => {
		expect(mainSource).toContain('installCaseLiveUpdates(router')
	})
})

describe('no widget on the case page polls', () => {
	it('declares no non-zero poll anywhere on CaseDetail', () => {
		expect(pollsUnder(casePage).filter((poll) => Number(poll) > 0)).toEqual([])
	})

	it('keeps the key rather than deleting it', () => {
		// 🔴 CnFlowRunsWidget's `pollMs()` falls back to 15 when `pollSeconds`
		// is undefined or null, so an ABSENT key is a fifteen-second poll that
		// the manifest does not mention. This is the assertion that tells the
		// two apart, and it is why the change sets 0 rather than removing a
		// line.
		const runs = pollsUnder(casePage)
		expect(runs).toContain(0)
		expect(runs).toHaveLength(1)
	})
})
