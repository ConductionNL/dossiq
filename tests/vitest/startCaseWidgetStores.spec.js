// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The Start a case Dashboard widget registers its stores before it reads.
 *
 * This widget mounts standalone on the Nextcloud Dashboard, in its own bundle
 * with its own pinia, where the object store starts with an EMPTY type
 * registry. It used to call `fetchCaseTypes()` straight from `mounted()`, so
 * `fetchCollection('caseType')` threw "not registered", the catch answered [],
 * and the widget said "No case types configured" on every instance whatever
 * the register held.
 *
 * What is asserted is the ORDER, not that something rendered: an empty state
 * renders perfectly well in both the broken and the fixed widget, which is why
 * nothing caught this. The registration must have RESOLVED before the first
 * fetch begins.
 *
 * @spec openspec/specs/signalering-widgets/spec.md
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** The order the two calls happened in, so it can be asserted. */
const events = []

vi.mock('../../src/store/store.js', () => ({
	initializeStores: vi.fn(async () => {
		events.push('initializeStores:start')
		// A real tick, so a caller that did not await would visibly run ahead.
		await Promise.resolve()
		events.push('initializeStores:resolved')
	}),
}))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchCollection: vi.fn(async () => {
			events.push('fetchCollection')
			return []
		}),
	}),
}))

// Named explicitly rather than spread. vitest.config aliases
// `@nextcloud/router` to tests/vitest/stubs/nextcloud-router.js, which exports
// only generateUrl, so `importOriginal()` returns that stub, not the package.
// The widget's imports also reach for imagePath, and a mock missing it fails
// at import time, before any assertion runs.
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
	imagePath: (app, file) => `/${app}/img/${file}`,
}))

const { default: StartCaseWidget } = await import('../../src/views/widgets/StartCaseWidget.vue')

describe('StartCaseWidget', () => {
	beforeEach(() => {
		events.length = 0
	})

	it('registers the stores, and waits for them, before it fetches a case type', async () => {
		const fetchSpy = vi.spyOn(StartCaseWidget.methods, 'fetchCaseTypes').mockImplementation(function () {
			events.push('fetchCaseTypes')
		})

		shallowMount(StartCaseWidget, {
			global: { mocks: { t: (_app, s) => s } },
		})
		await flushPromises()

		expect(fetchSpy).toHaveBeenCalledTimes(1)
		// The fetch must come AFTER the registration RESOLVED, not merely after
		// it started. A `mounted()` that called initializeStores() without
		// awaiting it would record start, fetchCaseTypes, resolved, and the
		// fetch would still hit an empty registry.
		expect(events.indexOf('initializeStores:resolved')).toBeGreaterThanOrEqual(0)
		expect(events.indexOf('fetchCaseTypes')).toBeGreaterThan(
			events.indexOf('initializeStores:resolved'),
		)

		fetchSpy.mockRestore()
	})
})
