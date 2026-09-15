/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A connection is tested from its own screen, and a connection nobody tested
 * does not read as working.
 *
 * 🔑 UNTESTED IS NOT FAILED. A red cross on a connection nobody probed sends
 * somebody debugging a working integration, and a green that was never probed
 * is worse than no button at all. Both are asserted here rather than left to
 * the component.
 */

import { beforeEach, describe, expect, it } from 'vitest'
import axios from '@nextcloud/axios'
import { testStufEndpoint } from '../../src/services/connectionTestApi.js'
import { connectionLabel } from '../../src/utils/starterStates.js'

describe('what a connection reads as', () => {
	it('reads as not tested before anybody presses the button', () => {
		const label = connectionLabel(null)

		expect(label.state).toBe('not_tested')
		expect(label.label).toBe('Not tested')
		expect(label.type).toBe('warning')
		expect(label.measuredAt).toBe('')
	})

	it('does not read an untested connection as working', () => {
		expect(connectionLabel(null).type).not.toBe('success')
		expect(connectionLabel({ state: 'not_tested' }).type).not.toBe('success')
	})

	it('names the status when the endpoint answered', () => {
		const label = connectionLabel({
			state: 'reachable',
			status: 200,
			measuredAt: '2026-09-15T08:00:00+00:00',
		})

		expect(label.type).toBe('success')
		expect(label.label).toBe('Answered 200')
		expect(label.measuredAt).toBe('2026-09-15T08:00:00+00:00')
	})

	it('names the reason when it failed, and does not report success', () => {
		const label = connectionLabel({
			state: 'failed',
			reason: 'Could not resolve host zaken.example.org',
			measuredAt: '2026-09-15T08:00:00+00:00',
		})

		expect(label.type).toBe('error')
		expect(label.label).toBe('Could not resolve host zaken.example.org')
	})

	it('still says something when a failure carried no reason', () => {
		expect(connectionLabel({ state: 'failed' }).label).toBe('The endpoint did not answer')
	})

	it('carries the moment a week-old result was measured', () => {
		const label = connectionLabel({
			state: 'reachable',
			status: 200,
			measuredAt: '2026-09-08T08:00:00+00:00',
		})

		expect(label.measuredAt).toBe('2026-09-08T08:00:00+00:00')
	})
})

describe('connectionTestApi.testStufEndpoint', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('probes the endpoint by its id, never by a URL off the screen', async () => {
		axios.post.mockResolvedValue({ data: { state: 'reachable', status: 200 } })

		const result = await testStufEndpoint('ep-1')

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/connections/stuf/ep-1/test')
		expect(result.state).toBe('reachable')
	})
})
