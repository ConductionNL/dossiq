// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The message template body is the library's CnMarkdownEditor as a plain-text
 * field with {{name}} variables: it previews the body escaped, marks known and
 * unknown variables and names the unknown ones. The subject keeps its own
 * check, and a variable clicked in the sidebar still lands in the field last
 * focused.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A stub that renders its default slot.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function boxStub(name, tag = 'div') {
	return {
		name,
		emits: ['click', 'focus'],
		render() {
			return h(tag, { class: name, onClick: () => this.$emit('click') }, this.$slots.default ? this.$slots.default() : [])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: boxStub('NcButton', 'button'),
	NcInputField: boxStub('NcInputField'),
	NcLoadingIcon: boxStub('NcLoadingIcon', 'span'),
	NcNoteCard: boxStub('NcNoteCard'),
	NcSelect: boxStub('NcSelect'),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const EmailTemplateAdmin = (await import('../../src/views/casetypes/components/EmailTemplateAdmin.vue')).default

const VARIABLES = { case: ['zaakNummer', 'zaakTitel'], contact: ['contactNaam'] }

/**
 * Mount the editor with one template open.
 *
 * @param {object} template The template.
 * @return {Promise<object>} The wrapper.
 */
async function openEditor(template) {
	const w = mount(EmailTemplateAdmin, { props: { caseTypeId: 'ct-1' } })
	await flushPromises()
	w.vm.selectTemplate({ id: 't-1', name: 'Besluit', ...template })
	await flushPromises()
	return w
}

/**
 * The props the body editor was handed.
 *
 * @param {object} w The wrapper.
 * @return {object} The props.
 */
function editorProps(w) {
	return JSON.parse(w.find('[data-testid="cn-markdown-editor"]').attributes('data-props'))
}

describe('the message template body', () => {
	beforeEach(() => {
		globalThis.t = (app, text) => text
		globalThis.OC = { requestToken: 'tok' }
		globalThis.fetch = vi.fn((url) => Promise.resolve({
			ok: true,
			json: () => Promise.resolve(String(url).endsWith('/variables') ? VARIABLES : []),
		}))
	})

	it('is a plain-text editor with {{name}} variables, each described by its group', async () => {
		const props = editorProps(await openEditor({ subject: 'S', body: 'Zaak {{zaakNummer}}' }))
		expect(props).toMatchObject({ modelValue: 'Zaak {{zaakNummer}}', mode: 'split', variableSyntax: '{{name}}', ariaLabel: 'Body' })
		// A bare `plainText` attribute reaches the untyped stand-in as '';
		// the library's Boolean prop reads it as true.
		expect([true, '']).toContain(props.plainText)
		expect(props.variables).toEqual([
			{ name: 'zaakNummer', description: 'Case' },
			{ name: 'zaakTitel', description: 'Case' },
			{ name: 'contactNaam', description: 'Contact' },
		])
		expect(props.unknownVariableText).toBe('Unresolved variables: {names}')
	})

	it('keeps what is typed in the body', async () => {
		const w = await openEditor({ subject: 'S', body: '' })
		w.findComponent({ name: 'CnMarkdownEditor' }).vm.$emit('update:modelValue', 'Beste {{contactNaam}}')
		expect(w.vm.draft.body).toBe('Beste {{contactNaam}}')
	})

	it('names the subject\'s unresolved variables only; the body editor names its own', async () => {
		const w = await openEditor({ subject: 'Zaak {{onbekend}}', body: '{{ookOnbekend}}' })
		expect(w.vm.unresolved).toEqual(['onbekend'])
		expect(w.find('.email-template-admin__warning').text()).toContain('onbekend')
		expect(w.find('.email-template-admin__warning').text()).not.toContain('ookOnbekend')
	})

	it('inserts a sidebar variable into the body once the body was focused after the subject', async () => {
		const w = await openEditor({ subject: '', body: 'Beste ' })
		w.vm.activeField = 'subject'
		await w.find('[data-testid="cn-markdown-editor"]').element.parentElement.dispatchEvent(new Event('focusin'))
		w.vm.insertVariable('contactNaam')
		expect(w.vm.draft.body).toBe('Beste {{contactNaam}}')
		expect(w.vm.draft.subject).toBe('')
	})
})
