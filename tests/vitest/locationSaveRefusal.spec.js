// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakLocatie: a refused save names why, above the buttons.
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md
 */

import { describe, expect, it } from 'vitest'
import { dialogNotice, dialogNoticeProps } from '../../src/utils/dialogNotice.js'
import { locationSaveRefusal } from '../../src/utils/locationSaveRefusal.js'

describe('locationSaveRefusal', () => {
	it('names the address the BAG does not know', () => {
		expect(locationSaveRefusal({ code: 'bag-address-unknown', addressId: '0642200000099999' })).toEqual({
			variant: 'error',
			role: 'alert',
			layout: 'stacked',
			title: 'Save refused: this address is not in the BAG',
			text: 'Address designation 0642200000099999 was not found. Choose an address from the list or check the house number.',
		})
		expect(locationSaveRefusal({ code: 'bag-address-unknown' }).text).toBe('The address was not found. Choose an address from the list or check the house number.')
	})

	it('says when the BAG check itself could not run', () => {
		expect(locationSaveRefusal({ code: 'bag-check-unavailable' })).toMatchObject({
			variant: 'warning',
			role: 'alert',
			title: 'The check itself could not run',
		})
	})

	it('keeps the server\'s own sentence for any other refusal', () => {
		expect(locationSaveRefusal({ message: 'Not allowed.' })).toMatchObject({ title: 'The location could not be saved.', text: 'Not allowed.' })
		expect(locationSaveRefusal(undefined)).toMatchObject({ title: 'The location could not be saved.', text: '' })
	})
})

describe('dialogNoticeProps', () => {
	it('binds nothing while the dialog does not draw notices', () => {
		expect(dialogNoticeProps(dialogNotice('error', 'x'), { props: { name: {} } })).toEqual({})
	})

	it('hands the notice to a dialog that draws notices, under the form', () => {
		const notice = dialogNotice('success', 'Saved')
		expect(notice.role).toBe('status')
		expect(dialogNoticeProps(notice, { props: { notices: {} } })).toEqual({ notices: [notice], noticesPlacement: 'bottom' })
		expect(dialogNoticeProps(null, { props: { notices: {} } })).toEqual({ notices: [], noticesPlacement: 'bottom' })
	})
})
