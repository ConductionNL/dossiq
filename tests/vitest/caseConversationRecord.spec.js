/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The conversation record as the case page reads it.
 *
 * A conversation that only Talk remembers is a conversation the archive never
 * sees, so the case carries the moment, who joined and how long it lasted, and
 * the Communication tab shows it. What can go wrong here is silent in a
 * browser: a section whose `type` no registry entry answers to draws an empty
 * panel and logs nothing, an icon outside `src/icons.js` renders the default
 * glyph, and a summary line that drops the participants looks exactly like a
 * conversation nobody joined.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const manifest = readJson('src', 'manifest.json')
const register = readJson('lib', 'Settings', 'dossiq_register.json')
const registrySource = read('src', 'registry.js')
const iconsSource = read('src', 'icons.js')
const panelSource = read(
	'src',
	'views',
	'cases',
	'components',
	'CaseConversationsPanel.vue',
)

const caseDetail = manifest.pages.find((p) =>
	(p.config?.widgets || []).some((w) => w.id === 'case-communication-panel'),
)
const communicationPanel = caseDetail.config.widgets.find(
	(w) => w.id === 'case-communication-panel',
)
const section = communicationPanel.content.sections.find(
	(s) => s.widget?.id === 'case-conversations',
)

describe('the live conversation section on the case', () => {
	it('sits in the Communication tab, beside the contact moments', () => {
		expect(section).toBeDefined()
		expect(section.label).toBe('Live conversation')
	})

	it('names a type the widget registry actually answers to', () => {
		expect(section.widget.type).toBe('case-conversations-pane')
		expect(registrySource).toContain("'case-conversations-pane'")
		expect(registrySource).toContain('component: CaseConversationsPanel')
	})

	it('names an icon the icon registry carries', () => {
		expect(section.widget.icon).toBe('PhoneInTalk')
		expect(iconsSource).toContain('PhoneInTalk')
	})
})

describe('the record the case keeps of a conversation', () => {
	const caseSchema = register.components.schemas.case

	it('declares conversations on the case, so the store does not strip them', () => {
		expect(caseSchema.properties.conversations).toBeDefined()
		expect(caseSchema.properties.conversations.type).toBe('array')
	})

	it('declares the major fields the declaration writes', () => {
		for (const field of [
			'isMajor',
			'majorChannel',
			'majorDeclaredBy',
			'majorDeclaredAt',
			'majorResponders',
		]) {
			expect(
				caseSchema.properties[field],
				`case.${field} is not declared`,
			).toBeDefined()
		}
	})

	it('declares the responders on the case type the channel opens with', () => {
		const caseType = register.components.schemas.caseType
		expect(caseType.properties.responders).toBeDefined()
		expect(caseType.properties.responders.type).toBe('array')
	})

	it('notifies the responders declaratively rather than dispatching, per ADR-031', () => {
		const rule = caseSchema['x-openregister-notifications'].caseDeclaredMajor
		expect(rule).toBeDefined()
		// The responders are still declared, and still FIRST. `case-followers`
		// (row 13.18) added the case's watchers beside them, which is what
		// somebody following a sensitive case is following it for. What this
		// assertion has always been guarding is that dossiq names the
		// recipients on the schema rather than dispatching to them in code, so
		// it names the whole list rather than only the block it cares about: a
		// `some()` here would let the responders be dropped in silence.
		expect(rule.recipients).toEqual([
			{ kind: 'field', field: 'majorResponders' },
			{ watchers: true },
		])
		expect(rule.subject.nl).toBeTruthy()
		expect(rule.subject.en).toBeTruthy()
	})

	it('fires at the declaration, not on every later save of a major case', () => {
		// A `filter` here would be read by NOBODY: OpenRegister's
		// AnnotationNotificationDispatcher honours `filter` only on a `created`
		// trigger, and an `updated` rule carrying one matches on type alone. So
		// the responders would be notified again every time anyone saved the
		// case. The engine's field-change `condition` is the shape that fires
		// once, when isMajor goes from absent-or-false to true.
		const trigger =
			caseSchema['x-openregister-notifications'].caseDeclaredMajor.trigger
		expect(trigger.type).toBe('updated')
		expect(
			trigger.filter,
			'an updated trigger ignores filter; this rule would fire on every save',
		).toBeUndefined()
		expect(trigger.condition).toEqual({
			field: 'isMajor',
			operator: 'equals',
			value: true,
			from: false,
		})
	})
})

describe('the panel that shows it', () => {
	it('shows the moment, who joined and how long, not just the moment', () => {
		expect(panelSource).toContain('recordSummary')
		expect(panelSource).toContain('record.startedAt')
		expect(panelSource).toContain('record?.participants')
		expect(panelSource).toContain('record?.durationSeconds')
	})

	it('says why rather than offering a conversation the instance cannot hold', () => {
		expect(panelSource).toContain('talkUnavailable')
		expect(panelSource).toContain('Talk is not available')
	})

	it('opens the one channel a second declaration would otherwise duplicate', () => {
		expect(panelSource).toContain('openMajorChannel')
		expect(panelSource).toContain('Open the working channel')
	})

	it('ships no recorder of its own, per decision D13 and design D-3', () => {
		expect(panelSource).not.toContain('MediaRecorder')
		expect(panelSource).not.toContain('SpeechRecognition')
	})
})
