// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The workflow editor's status node and its step and transition panels,
 * operated through their rendered controls rather than their methods: what
 * typing, choosing, ticking, clicking, pressing keys and dragging in the DOM
 * emits.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-keyboard-operable-canvas
 * @spec openspec/specs/status-transition-engine/spec.md
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/vue', () => {
	const { h: render } = require('vue')
	const button = (name) => ({
		name,
		emits: ['click'],
		render() {
			return render('button', { type: 'button', onClick: (e) => this.$emit('click', e) }, this.$slots.default?.())
		},
	})
	return {
		NcActionButton: button('NcActionButton'),
		NcActions: { name: 'NcActions', render() { return render('div', { class: 'nc-actions' }, this.$slots.default?.()) } },
		NcButton: button('NcButton'),
	}
})
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: { name: 'CloseIcon', render: () => h('span') } }))

const { default: WorkflowNode } = await import('../../src/views/settings/components/WorkflowNode.vue')
const { default: StepConfigPanel } = await import('../../src/views/settings/components/StepConfigPanel.vue')
const { default: TransitionConfigPanel } = await import('../../src/views/settings/components/TransitionConfigPanel.vue')

/**
 * The last payload a wrapper emitted under `update`.
 *
 * @param {object} w The wrapper.
 * @return {object} The payload.
 */
const lastUpdate = (w) => w.emitted('update').at(-1)[0]

/**
 * The rendered button whose text is exactly `text`.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {object} The button wrapper.
 */
function buttonNamed(w, text) {
	const found = w.findAll('button').filter((b) => b.text().trim() === text)
	expect(found, `one button "${text}"`).toHaveLength(1)
	return found[0]
}

/**
 * A minimal DataTransfer double that keeps what is set on it.
 *
 * @return {object} The double.
 */
function dataTransfer() {
	const data = {}
	return {
		effectAllowed: null,
		setData: (type, value) => { data[type] = value },
		getData: (type) => data[type] ?? '',
	}
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('WorkflowNode through its DOM', () => {
	const status = { id: 'a', name: 'Intake', isFinal: true }
	const steps = [
		{ id: 's1', title: 'Check', isRequired: true, checklist: [{ id: 'c1' }, { id: 'c2' }] },
		{ id: 's2', title: 'Decide' },
	]
	const props = {
		status,
		steps,
		selected: true,
		otherStatuses: [{ id: 'b', name: 'Review' }, { id: 'c', name: 'Closed' }],
		outgoingTransitions: [{ id: 't1', toStatus: 'c' }, { id: 't2', toStatus: 'gone' }],
	}

	it('renders its name, step count, final badge, required steps and checklist counts', () => {
		const w = mount(WorkflowNode, { props })
		expect(w.classes()).toEqual(expect.arrayContaining(['workflow-node--selected', 'workflow-node--final']))
		expect(w.attributes('aria-label')).toBe('Status: Intake')
		expect(w.find('.workflow-node__badge').text()).toBe('2')
		expect(w.find('.workflow-node__final-badge').text()).toBe('Final')
		const rows = w.findAll('.workflow-node__step')
		expect(rows.map((r) => r.classes().includes('workflow-node__step--required'))).toEqual([true, false])
		expect(rows[0].find('.workflow-node__step-checks').text()).toBe('2')
		expect(rows[1].find('.workflow-node__step-checks').exists()).toBe(false)
	})

	it('shows no badges or step list for an empty, non-final status', () => {
		const w = mount(WorkflowNode, { props: { status: { id: 'x', name: 'Open', isFinal: false } } })
		expect(w.find('.workflow-node__badge').exists()).toBe(false)
		expect(w.find('.workflow-node__final-badge').exists()).toBe(false)
		expect(w.find('.workflow-node__steps').exists()).toBe(false)
	})

	it('is selected by click, Enter and Space', async () => {
		const w = mount(WorkflowNode, { props })
		await w.trigger('click')
		await w.trigger('keydown', { key: 'Enter' })
		await w.trigger('keydown', { key: ' ' })
		expect(w.emitted('select')).toHaveLength(3)
	})

	it('starts a drag from the node and a connection from its output port, and ends one on its input port', async () => {
		const w = mount(WorkflowNode, { props })
		await w.trigger('mousedown', { offsetX: 4, offsetY: 9 })
		expect(w.emitted('drag-start')[0][0]).toEqual({ offsetX: expect.any(Number), offsetY: expect.any(Number) })
		await w.find('.workflow-node__port--output').trigger('mousedown')
		expect(w.emitted('connection-start')).toHaveLength(1)
		expect(w.emitted('connection-start')[0][0]).toBeInstanceOf(Event)
		expect(w.emitted('drag-start')).toHaveLength(1)
		await w.find('.workflow-node__port--input').trigger('mouseup')
		expect(w.emitted('connection-end')).toHaveLength(1)
	})

	it('offers connect, disconnect, add and delete from its keyboard menu', async () => {
		const w = mount(WorkflowNode, { props })
		const menu = w.find('.nc-actions')
		expect(menu.findAll('button').map((b) => b.text())).toEqual([
			'Connect to Review',
			'Disconnect from Closed',
			'Disconnect from gone',
			'Add step',
			'Delete status',
		])
		await buttonNamed(menu, 'Connect to Review').trigger('click')
		await buttonNamed(menu, 'Disconnect from Closed').trigger('click')
		await buttonNamed(menu, 'Add step').trigger('click')
		await buttonNamed(menu, 'Delete status').trigger('click')
		expect(w.emitted('keyboard-connect')).toEqual([['b']])
		expect(w.emitted('keyboard-disconnect')).toEqual([['t1']])
		expect(w.emitted('add-step')).toHaveLength(1)
		expect(w.emitted('delete-status')).toHaveLength(1)
		expect(w.emitted('select')).toBeUndefined()
	})

	it('adds a step from its own button without selecting the node', async () => {
		const w = mount(WorkflowNode, { props })
		await w.find('.workflow-node__add-step').trigger('click')
		expect(w.emitted('add-step')).toHaveLength(1)
		expect(w.emitted('select')).toBeUndefined()
	})

	it('opens a step by click without selecting the node, and by Enter and Space', async () => {
		const w = mount(WorkflowNode, { props })
		const row = w.findAll('.workflow-node__step')[1]
		await row.trigger('click')
		expect(w.emitted('step-click')).toEqual([[steps[1]]])
		expect(w.emitted('select')).toBeUndefined()
		await row.trigger('keydown', { key: 'Enter' })
		await row.trigger('keydown', { key: ' ' })
		expect(w.emitted('step-click')).toEqual([[steps[1]], [steps[1]], [steps[1]]])
	})

	it('reorders a step dragged onto another, and not one dropped on itself', async () => {
		const w = mount(WorkflowNode, { props })
		const [first, second] = w.findAll('.workflow-node__step')
		const transfer = dataTransfer()
		await first.trigger('dragstart', { dataTransfer: transfer })
		expect(w.vm.draggedStepId).toBe('s1')
		expect(transfer.effectAllowed).toBe('move')
		await first.trigger('drop', { dataTransfer: transfer })
		expect(w.emitted('step-reorder')).toBeUndefined()
		expect(w.vm.draggedStepId).toBeNull()
		await first.trigger('dragstart', { dataTransfer: transfer })
		await second.trigger('drop', { dataTransfer: transfer })
		expect(w.emitted('step-reorder')).toEqual([[{ draggedId: 's1', targetId: 's2' }]])
		expect(w.vm.draggedStepId).toBeNull()
	})
})

describe('StepConfigPanel through its DOM', () => {
	const roleTypes = [{ id: 'r1', name: 'Handler' }, { id: 'r2', name: 'Lead' }]
	const step = {
		id: 's1',
		title: 'Intake',
		description: '',
		isRequired: false,
		assigneeRole: null,
		checklist: [{ id: 'c1', label: 'A' }, { id: 'c2', label: 'B' }],
		automaticActions: [{ type: 'createTask', title: 'Call' }, { type: 'webhook', url: 'https://old.example' }],
	}

	it('emits the edited title, description, required flag and assignee role', async () => {
		const w = mount(StepConfigPanel, { props: { step, roleTypes } })
		await w.find('#step-config-title').setValue('Screening')
		expect(lastUpdate(w).title).toBe('Screening')
		await w.find('#step-config-description').setValue('First look')
		expect(lastUpdate(w).description).toBe('First look')
		await w.find('#step-required').setValue(true)
		expect(lastUpdate(w).isRequired).toBe(true)
		const roles = w.find('.step-config-panel__field select')
		expect(roles.findAll('option').map((o) => o.text())).toEqual(['Any role', 'Handler', 'Lead'])
		await roles.setValue('r2')
		expect(lastUpdate(w)).toMatchObject({ id: 's1', title: 'Screening', assigneeRole: 'r2' })
		expect(step.title).toBe('Intake')
	})

	it('deletes and closes from the header, and hides delete when read-only', async () => {
		const w = mount(StepConfigPanel, { props: { step } })
		await buttonNamed(w, 'Delete step').trigger('click')
		await w.find('[aria-label="Close step configuration"]').trigger('click')
		expect(w.emitted('delete')).toEqual([['s1']])
		expect(w.emitted('close')).toHaveLength(1)

		const ro = mount(StepConfigPanel, { props: { step, readOnly: true } })
		expect(ro.findAll('button').some((b) => b.text() === 'Delete step')).toBe(false)
		expect(ro.find('.step-config-panel__advanced-banner').exists()).toBe(true)
	})

	it('edits, adds, removes and drags checklist items', async () => {
		const w = mount(StepConfigPanel, { props: { step } })
		const items = () => w.findAll('.step-config-panel__checklist-item')
		await items()[0].find('input').setValue('A2')
		expect(lastUpdate(w).checklist.map((c) => c.label)).toEqual(['A2', 'B'])
		await buttonNamed(w, 'Add checklist item').trigger('click')
		expect(lastUpdate(w).checklist).toHaveLength(3)
		expect(lastUpdate(w).checklist[2]).toMatchObject({ label: '', description: '' })
		await items()[2].find('[aria-label="Remove checklist item"]').trigger('click')
		expect(lastUpdate(w).checklist.map((c) => c.id)).toEqual(['c1', 'c2'])

		const transfer = dataTransfer()
		await items()[0].trigger('dragstart', { dataTransfer: transfer })
		expect(transfer.effectAllowed).toBe('move')
		await items()[1].trigger('drop', { dataTransfer: transfer })
		expect(lastUpdate(w).checklist.map((c) => c.id)).toEqual(['c2', 'c1'])
		const updates = w.emitted('update').length
		await items()[1].trigger('drop', { dataTransfer: transfer })
		expect(w.emitted('update')).toHaveLength(updates)
	})

	it('keeps the advanced section closed until toggled', async () => {
		const w = mount(StepConfigPanel, { props: { step } })
		expect(w.find('#step-config-sla-value').exists()).toBe(false)
		expect(w.find('.step-config-panel__advanced-caret').text()).toBe('▸')
		await w.find('.step-config-panel__advanced-toggle').trigger('click')
		expect(w.find('.step-config-panel__advanced-caret').text()).toBe('▾')
		expect(w.find('#step-config-sla-value').exists()).toBe(true)
		await w.find('.step-config-panel__advanced-toggle').trigger('click')
		expect(w.find('#step-config-sla-value').exists()).toBe(false)
	})

	it('emits an SLA, required fields and escalation set in the advanced section', async () => {
		const w = mount(StepConfigPanel, { props: { step } })
		await w.find('.step-config-panel__advanced-toggle').trigger('click')
		await w.find('#step-config-sla-value').setValue('5')
		await w.find('.step-config-panel__sla-row select').setValue('businessDays')
		expect(lastUpdate(w).config).toEqual({ sla: { value: 5, unit: 'businessDays' } })

		await buttonNamed(w, 'Add field').trigger('click')
		await buttonNamed(w, 'Add field').trigger('click')
		const fields = () => w.findAll('.step-config-panel__required-field input')
		expect(fields()).toHaveLength(2)
		await fields()[0].setValue('result')
		expect(lastUpdate(w).config.requiredFields).toEqual(['result'])
		await w.findAll('.step-config-panel__required-field')[0].find('button').trigger('click')
		expect(lastUpdate(w).config).not.toHaveProperty('requiredFields')

		expect(w.find('.step-config-panel__escalation').exists()).toBe(false)
		await w.find('.step-config-panel__advanced-body input[type="checkbox"]').setValue(true)
		expect(lastUpdate(w).config).toHaveProperty('escalationRule')
		const escalation = w.find('.step-config-panel__escalation')
		await escalation.find('select').setValue('slaBreached')
		await escalation.find('[aria-label="Escalation offset"]').setValue('2')
		await escalation.findAll('select')[1].setValue('businessDays')
		await escalation.find('[aria-label="Warn role (UUID)"]').setValue('role-warn')
		await escalation.find('[aria-label="Escalate to role (UUID)"]').setValue('role-up')
		await escalation.find('.step-config-panel__inline-check input').setValue(true)
		expect(lastUpdate(w).config.escalationRule).toMatchObject({
			trigger: 'slaBreached',
			offset: 2,
			offsetUnit: 'businessDays',
			notifyRole: 'role-warn',
			escalateToRole: 'role-up',
			openIncident: true,
		})

		await w.find('.step-config-panel__advanced-body input[type="checkbox"]').setValue(false)
		expect(lastUpdate(w).config).not.toHaveProperty('escalationRule')
	})

	it('disables the advanced controls on a read-only step', async () => {
		const w = mount(StepConfigPanel, { props: { step: { ...step, config: { requiredFields: ['x'] } }, readOnly: true } })
		await w.find('.step-config-panel__advanced-toggle').trigger('click')
		expect(w.find('#step-config-sla-value').attributes('disabled')).toBeDefined()
		expect(w.find('.step-config-panel__required-field input').attributes('disabled')).toBeDefined()
		expect(w.find('.step-config-panel__advanced-body input[type="checkbox"]').attributes('disabled')).toBeDefined()
	})

	it('edits, switches, adds and removes automatic actions, keeping an old webhook editable', async () => {
		const w = mount(StepConfigPanel, { props: { step } })
		const actions = () => w.findAll('.step-config-panel__action')
		expect(actions()[0].find('[aria-label="Task title"]').element.value).toBe('Call')
		expect(actions()[1].find('[aria-label="Webhook URL"]').element.value).toBe('https://old.example')
		await actions()[1].find('[aria-label="Webhook URL"]').setValue('https://new.example')
		expect(lastUpdate(w).automaticActions[1]).toEqual({ type: 'webhook', url: 'https://new.example' })

		await actions()[0].find('select').setValue('notify')
		expect(actions()[0].find('[aria-label="Task title"]').exists()).toBe(false)
		await actions()[0].find('[aria-label="Notification message"]').setValue('Done')
		expect(lastUpdate(w).automaticActions[0]).toMatchObject({ type: 'notify', message: 'Done' })

		await buttonNamed(w, 'Add action').trigger('click')
		expect(lastUpdate(w).automaticActions.at(-1)).toEqual({ type: 'createTask', title: '' })
		await actions()[1].find('[aria-label="Remove action"]').trigger('click')
		expect(lastUpdate(w).automaticActions.map((a) => a.type)).toEqual(['notify', 'createTask'])
		expect(step.automaticActions).toHaveLength(2)
	})
})

describe('TransitionConfigPanel through its DOM', () => {
	const roleTypes = [{ id: 'r1', name: 'Handler' }, { id: 'r2', name: 'Lead' }]
	const documentTypes = [{ id: 'd1', name: 'Decision letter' }]
	const transition = { id: 't1', label: 'Approve', explanation: '', allowedRoles: ['r1'], guards: [], automaticActions: [] }

	it('emits the edited label and explanation, and closes', async () => {
		const w = mount(TransitionConfigPanel, { props: { transition } })
		await w.find('#transition-config-panel-label').setValue('Reject')
		expect(lastUpdate(w).label).toBe('Reject')
		await w.find('#transition-config-panel-explanation').setValue('Only with a reason')
		expect(lastUpdate(w)).toMatchObject({ id: 't1', label: 'Reject', explanation: 'Only with a reason' })
		await w.find('[aria-label="Close transition configuration"]').trigger('click')
		expect(w.emitted('close')).toHaveLength(1)
		expect(transition.label).toBe('Approve')
	})

	it('ticks the allowed roles and toggles them', async () => {
		const w = mount(TransitionConfigPanel, { props: { transition, roleTypes } })
		expect(w.find('#role-r1').element.checked).toBe(true)
		expect(w.find('#role-r2').element.checked).toBe(false)
		await w.find('#role-r2').trigger('change')
		expect(lastUpdate(w).allowedRoles).toEqual(['r1', 'r2'])
		await w.find('#role-r1').trigger('change')
		expect(lastUpdate(w).allowedRoles).toEqual(['r2'])
	})

	it('shows the input each guard type needs and emits what is filled in', async () => {
		const w = mount(TransitionConfigPanel, { props: { transition, roleTypes, documentTypes } })
		await buttonNamed(w, 'Add guard').trigger('click')
		expect(lastUpdate(w).guards).toEqual([{ type: 'checklist' }])
		const guard = () => w.find('.transition-config-panel__guard')
		expect(guard().findAll('select')).toHaveLength(1)
		expect(guard().find('input').exists()).toBe(false)

		await guard().find('select').setValue('requiredField')
		await guard().find('[aria-label="Field name (e.g. result)"]').setValue('result')
		expect(lastUpdate(w).guards).toEqual([{ type: 'requiredField', fieldName: 'result' }])

		await guard().find('select').setValue('requiredDocument')
		const docSelect = guard().findAll('select')[1]
		expect(docSelect.findAll('option').map((o) => o.text())).toEqual(['Select document type', 'Decision letter'])
		await docSelect.setValue('Decision letter')
		expect(lastUpdate(w).guards[0]).toMatchObject({ type: 'requiredDocument', documentTypeName: 'Decision letter' })

		await guard().find('select').setValue('roleGuard')
		const roleSelect = guard().findAll('select')[1]
		expect(roleSelect.findAll('option').map((o) => o.text())).toEqual(['Select role', 'Handler', 'Lead'])
		await roleSelect.setValue('r2')
		expect(lastUpdate(w).guards[0]).toMatchObject({ type: 'roleGuard', roleTypeId: 'r2' })

		await guard().find('[aria-label="Remove guard"]').trigger('click')
		expect(lastUpdate(w).guards).toEqual([])
	})

	it('shows the inputs each action type needs and emits what is filled in', async () => {
		const w = mount(TransitionConfigPanel, {
			props: { transition: { ...transition, automaticActions: [{ type: 'webhook', url: 'https://old.example' }] } },
		})
		const action = (i) => w.findAll('.transition-config-panel__action')[i]
		await action(0).find('[aria-label="Webhook URL"]').setValue('https://new.example')
		expect(lastUpdate(w).automaticActions[0]).toEqual({ type: 'webhook', url: 'https://new.example' })

		await buttonNamed(w, 'Add action').trigger('click')
		expect(lastUpdate(w).automaticActions[1]).toEqual({ type: 'notify', message: '' })
		await action(1).find('[aria-label="Notification message"]').setValue('Approved')
		expect(lastUpdate(w).automaticActions[1].message).toBe('Approved')

		await action(1).find('select').setValue('sendEmail')
		await action(1).find('[aria-label="Recipient (role name or email)"]').setValue('Handler')
		await action(1).find('[aria-label="Subject template"]').setValue('Approved')
		await action(1).find('textarea').setValue('Dear {{case.title}}')
		expect(lastUpdate(w).automaticActions[1]).toMatchObject({ type: 'sendEmail', recipient: 'Handler', subject: 'Approved', template: 'Dear {{case.title}}' })

		await action(1).find('select').setValue('createTask')
		await action(1).find('[aria-label="Task title"]').setValue('Archive')
		await action(1).find('[aria-label="Task description"]').setValue('Move the file')
		expect(lastUpdate(w).automaticActions[1]).toMatchObject({ type: 'createTask', title: 'Archive', description: 'Move the file' })

		await action(1).find('select').setValue('setField')
		await action(1).find('[aria-label="Field name"]').setValue('result')
		await action(1).find('[aria-label="Value"]').setValue('approved')
		expect(lastUpdate(w).automaticActions[1]).toMatchObject({ type: 'setField', fieldName: 'result', value: 'approved' })

		await action(0).find('[aria-label="Remove action"]').trigger('click')
		expect(lastUpdate(w).automaticActions.map((a) => a.type)).toEqual(['setField'])
	})

	it('deletes from its button only after confirmation', async () => {
		const confirm = vi.fn(() => false)
		vi.stubGlobal('confirm', confirm)
		const w = mount(TransitionConfigPanel, { props: { transition } })
		await buttonNamed(w, 'Delete transition').trigger('click')
		expect(confirm).toHaveBeenCalledWith('Are you sure you want to delete this transition?')
		expect(w.emitted('delete')).toBeUndefined()
		confirm.mockReturnValue(true)
		await buttonNamed(w, 'Delete transition').trigger('click')
		expect(w.emitted('delete')).toEqual([['t1']])
	})
})
