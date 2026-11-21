// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZakenKaart: the map list's line under a title is the case's address and
 * number, and its pills name the case type and the status.
 *
 * @spec openspec/specs/zakenkaart-map-list/spec.md#requirement-a-map-list-row-reads-address-case-number-and-names-its-type-and-status
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('caseAddress', () => {
	beforeEach(() => {
		vi.resetModules()
		get.mockReset()
	})

	it('reads the label of the case\'s first location, once', async () => {
		get.mockResolvedValue({ data: { results: [{ label: 'Lindelaan 40', formattedAddress: 'Lindelaan 40, Zuiddrecht' }] } })
		const { caseAddress } = await import('../../src/services/caseAddress.js')
		expect(caseAddress('c1')).toBe('')
		await flush()
		expect(caseAddress('c1')).toBe('Lindelaan 40')
		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][1]).toEqual({ params: { case: 'c1', _limit: 1 } })
	})

	it('is empty for a case without a location and for no id', async () => {
		get.mockResolvedValue({ data: { results: [] } })
		const { caseAddress } = await import('../../src/services/caseAddress.js')
		caseAddress('c2')
		await flush()
		expect(caseAddress('c2')).toBe('')
		expect(caseAddress('')).toBe('')
		expect(caseAddress(undefined)).toBe('')
	})
})

describe('the simple profile\'s map list', () => {
	const layout = JSON.parse(fs.readFileSync(path.join(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
	/**
	 * Every `mapConfig.list` in the layout.
	 *
	 * @param {unknown} node A layout node.
	 * @param {Array<object>} out The lists found.
	 * @return {Array<object>} The lists.
	 */
	const lists = (node, out = []) => {
		if (node && typeof node === 'object') {
			if (node.mapConfig && node.mapConfig.list) {
				out.push(node.mapConfig.list)
			}
			Object.values(node).forEach((child) => lists(child, out))
		}
		return out
	}

	it('reads address and number, else the number', () => {
		expect(lists(layout)[0].sub).toEqual(['{id|caseAddress} · {identifier}', '{identifier}'])
	})

	it('names the case type and the status in pills, each in its own colour', () => {
		expect(lists(layout)[0].pills).toEqual([
			{ path: 'caseType', formatter: 'caseTypeName', resolve: { register: 'dossiq', schema: 'caseType', colorField: 'colour' } },
			{ path: 'status', formatter: 'statusTypeName', resolve: { schema: 'statusType', colorField: 'colour' } },
		])
	})
})
