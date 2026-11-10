/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `CaseLiveSubscription.sync`
 * (src/services/caseLiveUpdates.js): a subscribe still in flight is not asked
 * again, a failed one is retried on the next sync, and the state it keeps
 * after each outcome.
 *
 * @spec openspec/changes/live-updates-on-the-case-page/specs/realtime-updates-ui/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { CaseLiveSubscription } from '../../src/services/caseLiveUpdates.js'

/**
 * A store whose subscribe answers when the test says so.
 *
 * @return {object} The store, with `answer(handle)` and `refuse(error)`.
 */
function pendingStore() {
	const waiting = []
	return {
		objectTypeRegistry: { case: {} },
		subscribe: vi.fn(() => new Promise((resolve, reject) => waiting.push({ resolve, reject }))),
		unsubscribe: vi.fn(),
		answer: (handle) => waiting.shift().resolve(handle),
		refuse: (error) => waiting.shift().reject(error),
	}
}

describe('CaseLiveSubscription.sync', () => {
	it('does not ask again for the case whose subscribe is in flight', async () => {
		const store = pendingStore()
		const live = new CaseLiveSubscription(store)
		const first = live.sync('c1')
		expect(live.pendingKey).toBe('c1')
		expect(live.key).toBe('c1')
		await live.sync('c1')
		expect(store.subscribe).toHaveBeenCalledTimes(1)
		store.answer({ id: 'h1' })
		await first
		expect(live.handle).toEqual({ id: 'h1' })
		expect(live.pendingKey).toBe('')
		expect(live.key).toBe('c1')
	})

	it('keeps the newer pending key when an older subscribe settles', async () => {
		const store = pendingStore()
		const live = new CaseLiveSubscription(store)
		const first = live.sync('c1')
		const second = live.sync('c2')
		expect(live.pendingKey).toBe('c2')
		store.answer({ id: 'old' })
		await first
		expect(store.unsubscribe).toHaveBeenCalledWith({ id: 'old' })
		expect(live.pendingKey).toBe('c2')
		expect(live.handle).toBeNull()
		store.answer({ id: 'new' })
		await second
		expect(live.handle).toEqual({ id: 'new' })
		expect(live.key).toBe('c2')
	})

	it('forgets a failed subscribe so the next sync tries again', async () => {
		const store = pendingStore()
		const live = new CaseLiveSubscription(store)
		const first = live.sync('c1')
		store.refuse({ message: 'transport down' })
		await first
		expect(live.handle).toBeNull()
		expect(live.key).toBe('')
		expect(live.pendingKey).toBe('')
		expect(live.lastError).toBe('transport down')
		const again = live.sync('c1')
		expect(store.subscribe).toHaveBeenCalledTimes(2)
		store.refuse('plain')
		await again
		expect(live.lastError).toBe('plain')
	})

	it('releases for no case, and when the case type is unregistered', async () => {
		const store = pendingStore()
		const live = new CaseLiveSubscription(store)
		live.handle = { id: 'held' }
		live.key = 'c0'
		const epoch = live.epoch
		await live.sync('')
		expect(store.unsubscribe).toHaveBeenCalledWith({ id: 'held' })
		expect(live.epoch).toBe(epoch + 1)
		store.objectTypeRegistry = null
		await live.sync('c1')
		expect(live.epoch).toBe(epoch + 2)
		expect(store.subscribe).not.toHaveBeenCalled()
	})

	it('does nothing on a store without subscribe', async () => {
		const live = new CaseLiveSubscription(null)
		await live.sync('c1')
		expect(live.epoch).toBe(0)
		expect(live.key).toBe('')
	})
})
