// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * These three dialogs RENDER, with a realistic object, and the values reach
 * the screen.
 *
 * 🔴 WHY A FULL MOUNT AND NOT A SHALLOW ONE. A shallow mount that never
 * evaluates the template cannot see the defect this file exists for: a rename
 * that moved a name in the `<script>` and left the `<template>` reading the
 * old one. Vue resolves an unknown root identifier to `undefined` and says
 * nothing, so the page renders empty, or a button stays disabled forever, and
 * every unit test still passes. `mount()` compiles and evaluates the template,
 * which is the only place the mismatch exists.
 *
 * All three were found by a `vue/no-undef-properties` sweep of `src/**` and
 * all three are the same shape, a Dutch name replaced by an English one:
 *
 *  - `DsoCaseDetail` was the third, and it is gone. It posted to
 *    /apps/dossiq/api/dso/cases/ and no page, route or schema resolved a DSO
 *    case, so nothing in src/ imported it and nobody could open it. Retired
 *    in retire-the-dead-dialogs; its block of this file went with it, and
 *    the two below, which cover dialogs a person can reach, did not.
 *  - `SamenwerkverzoekDialog` was the second, and it is gone too. It was a
 *    sub-dialog of `DsoCaseDetail` and nothing else ever imported it, so it
 *    became unreachable the moment that parent did. `BeschikkingDialog` and
 *    `DoorstuurDialog` were its two siblings and went the same way.
 *  - `BeschikkingComposerDialog` renamed its field to `rationale` and left the
 *    textarea writing to `motivering`, so typing a motivering did nothing and
 *    the composed decision went out without one. The dialog is now the
 *    Generate document picker (documents-on-the-case), and the same shape of
 *    drift is guarded on the field its submit reads: `templateId`.
 *
 * The `@nextcloud/vue` components are stubbed, for the reason
 * `workflowEditorSmoke.spec.js` gives at length: several chunks deep they pull
 * in the rich-text/reference-picker stack, which assumes a live Nextcloud
 * runtime and has nothing to do with a component's own template wiring. The
 * stubs still render their slots and still emit, so the bindings under test
 * are exercised, not skipped.
 *
 * @spec exclude regression guard for template/script name drift
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import BeschikkingComposerDialog from '../../src/dialogs/BeschikkingComposerDialog.vue'

// `BeschikkingComposerDialog` imports from the `@nextcloud/vue` BARREL, not
// from the per-component paths the other two use. Loading that barrel pulls
// the whole library through Vite's transform on a cold cache and blew the
// 5s test timeout, which reads as a failing assertion and is nothing of the
// kind. The barrel is replaced with the same stubs `stubs` below installs by
// name, so the component's own template is still compiled and evaluated.
vi.mock('@nextcloud/vue', async () => {
	const { h: create } = await import('vue')
	const box = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return create('div', { class: name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
	const field = (name) => ({
		name,
		props: {
			modelValue: { type: [String, Number, Object], default: '' },
			disabled: { type: Boolean, default: false },
			label: { type: String, default: '' },
		},
		emits: ['update:modelValue'],
		render() {
			return create('button', {
				class: name,
				disabled: this.disabled,
				'data-value': String(this.modelValue ?? ''),
			})
		},
	})

	return {
		NcButton: {
			name: 'NcButton',
			props: { disabled: { type: Boolean, default: false } },
			emits: ['click'],
			render() {
				return create(
					'button',
					{
						class: 'NcButton',
						disabled: this.disabled,
						onClick: (e) => this.$emit('click', e),
					},
					this.$slots.default?.(),
				)
			},
		},
		NcDialog: box('NcDialog'),
		NcLoadingIcon: box('NcLoadingIcon'),
		NcNoteCard: box('NcNoteCard'),
		NcSelect: field('NcSelect'),
		NcTextArea: field('NcTextArea'),
		NcTextField: field('NcTextField'),
	}
})

/**
 * A stub that renders its default slot, so slotted template content is still
 * compiled and evaluated.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function passthrough(name) {
	return {
		name,
		inheritAttrs: false,
		render() {
			return h('div', { class: name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

/**
 * A stub for the form controls, which must keep their modelValue visible and
 * their disabled state assertable.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function control(name) {
	return {
		name,
		props: {
			modelValue: { type: [String, Number, Object], default: '' },
			disabled: { type: Boolean, default: false },
			label: { type: String, default: '' },
		},
		emits: ['update:modelValue'],
		render() {
			return h('button', {
				class: name,
				disabled: this.disabled,
				'data-value': String(this.modelValue ?? ''),
				'data-label': this.label,
				onClick: () => this.$emit('update:modelValue', 'typed'),
			})
		},
	}
}

const stubs = {
	NcDialog: passthrough('NcDialog'),
	NcButton: {
		name: 'NcButton',
		props: { disabled: { type: Boolean, default: false } },
		emits: ['click'],
		render() {
			return h(
				'button',
				{
					class: 'NcButton',
					disabled: this.disabled,
					onClick: (e) => this.$emit('click', e),
				},
				this.$slots.default?.(),
			)
		},
	},
	NcLoadingIcon: passthrough('NcLoadingIcon'),
	NcNoteCard: passthrough('NcNoteCard'),
	NcSelect: control('NcSelect'),
	NcTextField: control('NcTextField'),
	NcTextArea: control('NcTextArea'),
}

describe('BeschikkingComposerDialog', () => {
	it('writes the picked template into the field the submit actually reads', async () => {
		const wrapper = mount(BeschikkingComposerDialog, {
			props: { open: true, caseId: 'case-1' },
			global: { stubs, mocks: { $route: { params: { id: 'case-1' } } } },
		})
		// The dialog fetches the library the moment it opens, and the picker
		// renders only once that settles.
		await flushPromises()

		const picker = wrapper.findComponent({ name: 'NcSelect' })
		expect(picker.exists()).toBe(true)

		await picker.vm.$emit('update:modelValue', 'ontvangstbevestiging')

		// `onGenerate()` reads `templateId` and refuses when it is empty, so a
		// picker bound to any other name leaves the Generate button inert with
		// nothing on screen to say why.
		expect(wrapper.vm.templateId).toBe('ontvangstbevestiging')
	})

	it('keeps Generate disabled until a template is picked', async () => {
		const wrapper = mount(BeschikkingComposerDialog, {
			props: { open: true, caseId: 'case-1' },
			global: { stubs, mocks: { $route: { params: { id: 'case-1' } } } },
		})
		await flushPromises()

		const generate = () =>
			wrapper.findAll('button.NcButton').find((b) => b.text() === 'Generate')

		expect(generate().attributes('disabled')).toBeDefined()

		await wrapper.setData({ templateId: 'ontvangstbevestiging' })
		expect(generate().attributes('disabled')).toBeUndefined()
	})
})
