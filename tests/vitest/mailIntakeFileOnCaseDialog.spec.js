// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Filing one logged message on a case a handler picked.
 *
 * THE DIALOG'S THREE PROMISES WERE WRITTEN DOWN AND NOT ASSERTED. The change
 * that built it says the case field is pre-filled with where the message is
 * now, that the confirm button is disabled without a reason, and that a 403
 * does NOT close the dialog. All three lived only in an e2e spec that no run
 * on this repo executes, so all three were claims rather than behaviour.
 *
 * THE 403 IS THE ONE THAT MATTERS MOST. Closing the dialog on a refusal tells
 * the handler the message moved when it did not, and a moved message is
 * exactly the thing they would not check again. An emitted `filed` and a
 * rendered error look identical from the outside unless something keeps them
 * apart.
 *
 * THE PRE-FILL IS NOT COSMETIC EITHER. The common gesture here is correcting
 * a wrong match, and a blank field beside a matcher that chose 2026-090 asks
 * the handler to remember what they are changing away from.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MailIntakeFileOnCaseDialog from '../../src/dialogs/MailIntakeFileOnCaseDialog.vue'

const stubs = {
	NcDialog: {
		props: { name: { type: String, default: '' } },
		template: '<div class="dialog"><h2>{{ name }}</h2><slot /></div>',
	},
	NcNoteCard: {
		props: { type: { type: String, default: '' } },
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
	NcButton: {
		// No explicit `$emit('click')`: the parent's `@click` falls through on
		// `$attrs` already, and emitting as well would post twice per press.
		props: { disabled: { type: Boolean, default: false } },
		template: '<button v-bind="$attrs" :disabled="disabled"><slot /></button>',
	},
	NcTextField: {
		props: {
			modelValue: { type: String, default: '' },
			label: { type: String, default: '' },
		},
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

/** The entry as the log answers it: the matcher put this one on 2026-090. */
const ENTRY = {
	id: 'entry-1',
	sender: 'aanvrager@voorbeeld.nl',
	subject: 'Bezwaar',
	case: '2026-090',
}

/**
 * Mount the dialog over one log entry.
 *
 * @param {object} entry The entry.
 * @return {object} The mounted wrapper.
 */
function mountDialog(entry = ENTRY) {
	return mount(MailIntakeFileOnCaseDialog, {
		props: { entry, entryId: 'entry-1' },
		global: { stubs },
	})
}

/**
 * The confirm button.
 *
 * @param {object} wrapper The mounted wrapper.
 * @return {object} The button.
 */
function confirmButton(wrapper) {
	return wrapper.find('[data-testid="intake-log-file-confirm"]')
}

describe('filing a logged message on a case the handler picks', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		global.OC = { requestToken: 'token-1' }
		global.fetch = vi.fn()
	})

	it('pre-fills the case the matcher chose rather than an empty field', () => {
		const wrapper = mountDialog()

		expect(wrapper.vm.caseId).toBe('2026-090')
		expect(
			wrapper.find('[data-testid="intake-log-case-id"]').element.value,
		).toBe('2026-090')
	})

	it('says in words that this records a correction and changes no rule', () => {
		const wrapper = mountDialog()

		expect(wrapper.text()).toContain('2026-090')
		expect(wrapper.text()).toContain('changes no matching rule')
	})

	it('refuses to send without a reason, before the click rather than after', async () => {
		const wrapper = mountDialog()

		expect(confirmButton(wrapper).attributes('disabled')).toBeDefined()

		await wrapper
			.find('[data-testid="intake-log-file-reason"]')
			.setValue('Hoort bij 2026-114.')

		expect(confirmButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('refuses to send without a case, even on an entry that became none', async () => {
		const wrapper = mountDialog({ ...ENTRY, case: '' })

		await wrapper
			.find('[data-testid="intake-log-file-reason"]')
			.setValue('Hoort bij 2026-114.')

		expect(confirmButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('posts the case and the reason the handler typed', async () => {
		global.fetch.mockResolvedValue({
			ok: true,
			json: async () => ({ filed: true }),
		})
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="intake-log-case-id"]').setValue('2026-114')
		await wrapper
			.find('[data-testid="intake-log-file-reason"]')
			.setValue('Hoort bij 2026-114.')
		await confirmButton(wrapper).trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(global.fetch).toHaveBeenCalledTimes(1)
		const [url, init] = global.fetch.mock.calls[0]
		expect(String(url)).toContain(
			'/apps/dossiq/api/mail-intake/log/entry-1/file-on-case',
		)
		expect(JSON.parse(init.body)).toEqual({
			caseId: '2026-114',
			reason: 'Hoort bij 2026-114.',
		})
		expect(wrapper.emitted('filed')).toBeTruthy()
	})

	it('🔴 keeps the dialog open on a 403 and says the message did not move', async () => {
		global.fetch.mockResolvedValue({
			ok: false,
			status: 403,
			json: async () => ({ message: 'Not authorized' }),
		})
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="intake-log-case-id"]').setValue('2026-200')
		await wrapper
			.find('[data-testid="intake-log-file-reason"]')
			.setValue('Hoort bij 2026-200.')
		await confirmButton(wrapper).trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('filed')).toBeFalsy()
		expect(wrapper.emitted('close')).toBeFalsy()
		expect(wrapper.find('[data-type="error"]').text()).toContain(
			'You cannot read that case',
		)
	})

	it('reports a refused case number without claiming it moved', async () => {
		global.fetch.mockResolvedValue({
			ok: false,
			status: 404,
			json: async () => ({ message: 'not_found' }),
		})
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="intake-log-case-id"]').setValue('2026-999')
		await wrapper
			.find('[data-testid="intake-log-file-reason"]')
			.setValue('Hoort bij 2026-999.')
		await confirmButton(wrapper).trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('filed')).toBeFalsy()
		expect(wrapper.find('[data-type="error"]').text()).toContain(
			'Check the case number',
		)
	})
})
