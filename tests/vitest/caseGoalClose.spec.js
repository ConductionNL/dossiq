// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * case-goals 2.1b: a goal on the case's goals row closes from its own row.
 * The row's Close entry opens the library's action dialog on that goal, asks
 * how it ended and what was seen, and posts to the case-scoped close (which
 * checks the case update right first). A closed goal offers no Close.
 *
 * @spec openspec/changes/case-goals/specs/case-goals/spec.md
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const stub = vi.hoisted(() => ({ component: null }))

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	stub.component = define({
		name: 'CnActionDialog',
		props: { config: { type: Object, default: () => ({}) }, objectId: { type: [String, Number], default: '' }, object: { type: Object, default: null } },
		emits: ['close', 'done'],
		render() {
			return render('div', { class: 'cn-action-dialog-stub' })
		},
	})
	return { CnActionDialog: stub.component }
})

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const goals = simple.pages.find((entry) => entry.id === 'CaseDetail').configAppend.widgets.find((entry) => entry.id === 'case-goals')
const close = (goals.content.rowActions || []).find((action) => action.id === 'close-goal')

describe('closing a goal from its row', () => {
	it('opens the row action dialog on the goal, for this case', () => {
		expect(close).toMatchObject({ type: 'open-modal', target: 'RowRecordActionDialog', props: { objectId: '@objectId' } })
	})

	it('posts to the case-scoped close, naming the case and the goal', () => {
		expect(close.props.config.confirm.request).toEqual({ method: 'POST', url: '/apps/dossiq/api/cases/@objectId/goals/@object.id/close' })
	})

	it('asks how the goal ended and what was seen, as the close needs them', () => {
		const [state] = close.props.config.fields
		expect(state.field).toBe('state')
		expect(state.options.map((option) => option.value)).toEqual(['met', 'not-met', 'withdrawn'])
		expect(close.props.config.reason.field).toBe('observation')
	})

	it('is not offered on a goal that is already closed', () => {
		expect(close.visibleWhen).toEqual({ field: 'closedDate', op: 'empty' })
	})

	it('reads as Dutch', () => {
		for (const text of [close.label, close.props.config.title, close.props.config.reason.label, close.props.config.doneText, ...close.props.config.fields[0].options.map((option) => option.label)]) {
			expect(nl[text], text).toBeTruthy()
		}
	})

	it('is a dialog the registry knows', () => {
		const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		expect(registry).toContain("import RowRecordActionDialog from './dialogs/RowRecordActionDialog.vue'")
		expect(registry).toMatch(/RowRecordActionDialog: \{\n\t\tkind: 'modal',\n\t\tcomponent: RowRecordActionDialog,/)
	})
})

describe('RowRecordActionDialog', () => {
	it('hands the row to the action dialog as its record, with the page record and the config', async () => {
		const { default: RowRecordActionDialog } = await import('../../src/dialogs/RowRecordActionDialog.vue')
		const row = { id: 'goal-7', title: 'Werk vinden', metWhen: 'Een baan' }
		const config = { title: 'Close goal' }

		const wrapper = mount(RowRecordActionDialog, { props: { row, config, objectId: 'case-1' } })
		const inner = wrapper.findComponent(stub.component)

		expect(inner.props('object')).toEqual(row)
		expect(inner.props('objectId')).toBe('case-1')
		expect(inner.props('config')).toEqual(config)
	})

	it('passes close and done on', async () => {
		const { default: RowRecordActionDialog } = await import('../../src/dialogs/RowRecordActionDialog.vue')
		const wrapper = mount(defineComponent({ render: () => h(RowRecordActionDialog, { row: { id: 'g' } }) }))
		const dialog = wrapper.findComponent(RowRecordActionDialog)

		dialog.findComponent(stub.component).vm.$emit('close')
		dialog.findComponent(stub.component).vm.$emit('done', { id: 'g' })

		expect(dialog.emitted('close')).toHaveLength(1)
		expect(dialog.emitted('done')).toEqual([[{ id: 'g' }]])
	})
})
