// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The notice-of-default row action: read the row from either dispatch shape
 * and open the payout dialog only for a definitive penalty with an amount.
 *
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const mounted = vi.hoisted(() => ({ props: [] }))

vi.mock('../../src/dialogs/PayoutPrepareDialog.vue', () => ({
	default: {
		name: 'PayoutPrepareDialogStub',
		props: ['notice'],
		emits: ['close'],
		setup(props, { emit }) {
			mounted.props.push({ notice: props.notice, close: () => emit('close') })
			return () => h('div', { class: 'payout-dialog-stub' }, String(props.notice.accruingAmountId))
		},
	},
}))

const { noticeRowOf, prepareNoticePayout } = await import('../../src/utils/noticePayout.js')

afterEach(() => {
	document.body.innerHTML = ''
	mounted.props.length = 0
})

describe('noticeRowOf', () => {
	it('reads the item of a CnIndexPage dispatch', () => {
		const item = { id: 'n-1' }
		expect(noticeRowOf([{ actionId: 'payout', item }])).toBe(item)
	})

	it('reads the row appended last by the object-table widget', () => {
		const row = { id: 'n-2' }
		expect(noticeRowOf(['payout', null, row])).toBe(row)
	})

	it('is null when no argument is an object', () => {
		expect(noticeRowOf(['payout', 3, null])).toBeNull()
		expect(noticeRowOf([])).toBeNull()
	})
})

describe('prepareNoticePayout', () => {
	const definitive = { id: 'n-1', accruingAmountId: 'amt-1', penaltyState: 'definitive' }

	it('opens the payout dialog for a definitive penalty and removes it on close', () => {
		prepareNoticePayout({ actionId: 'payout', item: definitive })

		expect(document.querySelectorAll('.payout-dialog-stub')).toHaveLength(1)
		expect(document.body.textContent).toBe('amt-1')
		expect(mounted.props).toHaveLength(1)
		expect(mounted.props[0].notice).toEqual(definitive)

		mounted.props[0].close()

		expect(document.querySelector('.payout-dialog-stub')).toBeNull()
		expect(document.body.children).toHaveLength(0)
	})

	it.each([
		['no row', []],
		['no amount', [{ item: { ...definitive, accruingAmountId: '' } }]],
		['a provisional penalty', [{ item: { ...definitive, penaltyState: 'provisional' } }]],
	])('opens nothing for %s', (_label, args) => {
		prepareNoticePayout(...args)

		expect(document.body.children).toHaveLength(0)
		expect(mounted.props).toHaveLength(0)
	})
})
