// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The three CaseTypeDetail tab widgets are thin shells, and the one thing each
 * does is the thing worth pinning: it reads the case type id off the route,
 * coerces it to a string, and hands it (and nothing else it was not asked for)
 * to the view that does the work.
 *
 * `caseTypeId` reads `$route.params.id`. A route whose id is a number — which
 * a router hands over verbatim — must reach the child as a string, or the
 * child's `/objects/dossiq/caseType/${id}` reads compare a number against a
 * stored string id and silently find nothing. A route with no id must give
 * '', never the literal `undefined`, for the same reason. And the Rules
 * widget alone maps `content.mode === 'summary'` onto the child's `summary`
 * flag, the Overview tab's reason for existing, so that mapping is asserted
 * both ways.
 *
 * Each child view is stubbed: it is the boundary, and mounting the real one
 * would pull in the settings tabs, their stores and their network. The stub
 * keeps the props it is given so the forwarding can be read back.
 *
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { defineComponent } from 'vue'
import CaseTypeMessagesEditorWidget from '../../src/components/caseType/CaseTypeMessagesEditorWidget.vue'
import CaseTypeRulesWidget from '../../src/components/caseType/CaseTypeRulesWidget.vue'
import CaseTypeWorkflowEditorWidget from '../../src/components/caseType/CaseTypeWorkflowEditorWidget.vue'

/**
 * A stand-in for a child view that records the props it is handed.
 *
 * @param {string} name The stub's name.
 * @return {object} The component.
 */
function childStub(name) {
	return defineComponent({
		name,
		props: ['caseTypeId', 'summary'],
		render: () => null,
	})
}

/**
 * Mount a widget with its child view stubbed and a route in place.
 *
 * @param {object} widget The widget component.
 * @param {string} childName The child view's registered name.
 * @param {object} route The `$route` the widget reads.
 * @param {object} props The widget's own props.
 * @return {object} The mounted child stub's props, and the wrapper.
 */
function mountWidget(widget, childName, route, props = {}) {
	const wrapper = shallowMount(widget, {
		props,
		global: {
			mocks: { $route: route },
			stubs: { [childName]: childStub(childName) },
		},
	})
	return { child: wrapper.findComponent({ name: childName }), wrapper }
}

describe('CaseTypeMessagesEditorWidget', () => {
	it('hands the route id, as a string, to the email template admin', () => {
		const { child } = mountWidget(CaseTypeMessagesEditorWidget, 'EmailTemplateAdmin', {
			params: { id: 42 },
		})

		expect(child.exists()).toBe(true)
		expect(child.props('caseTypeId')).toBe('42')
	})

	it('gives the child an empty id, never undefined, when the route has none', () => {
		const { child } = mountWidget(CaseTypeMessagesEditorWidget, 'EmailTemplateAdmin', {
			params: {},
		})

		expect(child.props('caseTypeId')).toBe('')
	})
})

describe('CaseTypeWorkflowEditorWidget', () => {
	it('hands the route id, as a string, to the workflow tab', () => {
		const { child } = mountWidget(CaseTypeWorkflowEditorWidget, 'WorkflowTab', {
			params: { id: 'zaaktype-7' },
		})

		expect(child.props('caseTypeId')).toBe('zaaktype-7')
	})

	it('reads the empty string from a missing route entirely', () => {
		const { child } = mountWidget(CaseTypeWorkflowEditorWidget, 'WorkflowTab', {})

		expect(child.props('caseTypeId')).toBe('')
	})
})

describe('CaseTypeRulesWidget', () => {
	it('hands the route id, as a string, to the rules tab', () => {
		const { child } = mountWidget(CaseTypeRulesWidget, 'RulesTab', { params: { id: 9 } })

		expect(child.props('caseTypeId')).toBe('9')
	})

	it('asks the rules tab for the summary only when the content says so', () => {
		const { child } = mountWidget(
			CaseTypeRulesWidget,
			'RulesTab',
			{ params: { id: 'ct-1' } },
			{ content: { mode: 'summary' } },
		)

		expect(child.props('summary')).toBe(true)
	})

	it('leaves the rules tab in full mode for any other content, and with no content at all', () => {
		const full = mountWidget(
			CaseTypeRulesWidget,
			'RulesTab',
			{ params: { id: 'ct-1' } },
			{ content: { mode: 'full' } },
		)
		expect(full.child.props('summary')).toBe(false)

		const none = mountWidget(CaseTypeRulesWidget, 'RulesTab', { params: { id: 'ct-1' } })
		expect(none.child.props('summary')).toBe(false)
	})
})
