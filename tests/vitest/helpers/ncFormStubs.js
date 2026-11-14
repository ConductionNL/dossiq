/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Plain stand-ins for the `@nextcloud/vue` form components the modals in
 * `src/modals/` use, for specs that mount a modal. Each renders the props a
 * spec wants to read (the dialog name, the options of a select, a button's
 * variant and disabled state) and emits nothing on its own: a spec drives a
 * component by emitting on its stand-in, e.g.
 * `select.vm.$emit('update:modelValue', option)`.
 *
 * Use from a `vi.mock` factory:
 *
 *     vi.mock('@nextcloud/vue', async () => (await import('./helpers/ncFormStubs.js')).ncFormStubs())
 */

import { defineComponent, h } from 'vue'

/**
 * Render a named slot, or nothing.
 *
 * @param {object} slots The component's slots.
 * @param {string} name The slot name.
 * @return {Array} The vnodes.
 */
function slot(slots, name = 'default') {
	return slots[name] ? slots[name]() : []
}

/**
 * The stand-ins, keyed by the export name of `@nextcloud/vue`.
 *
 * @return {object} The components.
 */
export function ncFormStubs() {
	const frame = (name, emits) => defineComponent({
		name,
		props: { name: String, open: Boolean, size: String },
		emits,
		setup(props, { slots }) {
			return () => h('div', { class: 'nc-frame' }, [
				h('h2', { class: 'nc-frame__name' }, props.name),
				...slot(slots),
				h('footer', slot(slots, 'actions')),
			])
		},
	})

	return {
		NcDialog: frame('NcDialog', ['update:open', 'closing']),
		NcModal: frame('NcModal', ['close']),
		NcButton: defineComponent({
			name: 'NcButton',
			props: { variant: String, type: String, disabled: Boolean },
			emits: ['click'],
			setup(props, { slots, emit }) {
				return () => h('button', {
					class: 'nc-button nc-button--' + (props.variant || props.type || 'secondary'),
					disabled: props.disabled,
					onClick: () => emit('click'),
				}, [...slot(slots, 'icon'), ...slot(slots)])
			},
		}),
		NcEmptyContent: defineComponent({
			name: 'NcEmptyContent',
			props: { name: String, description: String },
			setup(props) {
				return () => h('div', { class: 'nc-empty' }, [h('strong', props.name), h('p', props.description)])
			},
		}),
		NcLoadingIcon: defineComponent({ name: 'NcLoadingIcon', setup: () => () => h('span', { class: 'nc-loading' }) }),
		NcNoteCard: defineComponent({
			name: 'NcNoteCard',
			props: { type: String },
			setup(props, { slots }) {
				return () => h('div', { class: 'nc-note nc-note--' + props.type }, slot(slots))
			},
		}),
		NcSelect: defineComponent({
			name: 'NcSelect',
			props: { options: { type: Array, default: () => [] }, modelValue: null, multiple: Boolean },
			emits: ['update:modelValue'],
			setup(props) {
				return () => h('ul', { class: 'nc-select' }, props.options.map((o) => h('li', String(o?.label ?? o?.title ?? o?.displayName ?? o))))
			},
		}),
		NcTextField: defineComponent({
			name: 'NcTextField',
			props: { modelValue: [String, Number], error: Boolean, helperText: String },
			emits: ['update:modelValue'],
			setup(props) {
				return () => h('input', { class: props.error ? 'nc-text nc-text--error' : 'nc-text', value: props.modelValue, 'data-helper': props.helperText })
			},
		}),
		NcTextArea: defineComponent({
			name: 'NcTextArea',
			props: { modelValue: String },
			emits: ['update:modelValue'],
			setup(props) {
				return () => h('textarea', { class: 'nc-textarea', value: props.modelValue })
			},
		}),
		NcCheckboxRadioSwitch: defineComponent({
			name: 'NcCheckboxRadioSwitch',
			props: { modelValue: null },
			emits: ['update:modelValue'],
			setup(props, { slots }) {
				return () => h('label', { class: 'nc-switch', 'data-checked': String(!!props.modelValue) }, slot(slots))
			},
		}),
	}
}

/**
 * The stand-in component of one kind whose root carries the given id.
 *
 * @param {object} wrapper The mounted wrapper.
 * @param {string} name The component name, e.g. `NcSelect`.
 * @param {string} id The id attribute the template gives it.
 * @return {object} The component wrapper.
 */
export function byId(wrapper, name, id) {
	const hit = wrapper.findAllComponents({ name }).find((c) => c.attributes('id') === id)
	if (!hit) {
		throw new Error(`no ${name} with id ${id}`)
	}
	return hit
}
