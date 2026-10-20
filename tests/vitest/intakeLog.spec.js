/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The intake log as a surface: what the mailbox processed, what decided, and
 * why a message became no case. It is a manifest index over dossiq's own
 * endpoint; these assertions pin the declaration that keeps what the page did.
 *
 * 🔴 THE ASSERTION THAT CARRIES THIS SPEC IS THE ONE ON `unavailable`. A check
 * nobody made and a check that passed are different facts, so every result
 * column says pass, fail, no policy and not checked in four different words,
 * and the server answers a missing check as `unavailable` (pinned in
 * MailIntakeControllerTest), never blank.
 *
 * The second is the refusal. The log holds the original of every message the
 * mailbox received, so the page reads the endpoint that checks the intake
 * role, never the generic object endpoint, and a reader without the role sees
 * the refusal sentence instead of an empty log.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.d', '37-mail-intake.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

/** @return {object} The log page as the fragment declares it. */
function logPage() {
	return fragment.pages.find((page) => page.id === 'MailIntakeLog')
}

/**
 * One column of the log.
 *
 * @param {string} key The column key.
 * @return {object} The column.
 */
function column(key) {
	return logPage().config.columns.find((col) => col.key === key)
}

/** @return {object} The row actions by id. */
function actions() {
	return Object.fromEntries(logPage().config.actions.map((action) => [action.id, action]))
}

describe('the intake log', () => {
	it('reads the endpoint that checks the intake role, not the object endpoint', () => {
		const page = logPage()

		expect(page.route).toBe('/mail-intake-log')
		expect(page.type).toBe('index')
		expect(page.config.entitySource).toBe('endpoint')
		expect(page.config.sourceConfig).toMatchObject({ url: '/apps/dossiq/api/mail-intake/log', rowsPath: 'results' })
		expect(page.config.register).toBeUndefined()
		expect(registrySource).not.toContain('MailIntakeLogView')
	})

	it('names a check nobody made rather than leaving it blank', () => {
		for (const key of ['spfResult', 'dkimResult', 'dmarcResult', 'threadingResult']) {
			const col = column(key)
			expect(col.widget).toBe('badge')
			expect(col.enumLabels).toEqual({ pass: 'passed', fail: 'failed', none: 'no policy published', unavailable: 'not checked' })
			expect(new Set(Object.values(col.enumLabels)).size).toBe(4)
			// Colour says it too, but never alone: the word is in the badge.
			expect(col.colorMap).toMatchObject({ pass: 'success', fail: 'error' })
			expect(col.colorMap.unavailable).not.toBe('success')
		}
	})

	it('refuses a reader without the intake role in a sentence, in Dutch too', () => {
		const refusedText = logPage().config.sourceConfig.refusedText

		expect(refusedText).toContain('You do not have the intake role')
		expect(nl[refusedText]).toBeTruthy()
	})

	it('finds a message by its sender', () => {
		expect(logPage().config.inlineSearch).toBe(true)
		expect(logPage().config.sourceConfig.searchParam).toBe('sender')
	})

	it('narrows by outcome with a tab per outcome', () => {
		const filters = logPage().config.quickFilters

		expect(filters[0]).toMatchObject({ label: 'Every outcome', filter: {}, default: true })
		expect(filters.slice(1).map((tab) => tab.filter.outcome)).toEqual(['case', 'quarantined', 'released', 'inbox', 'refused', 'forwarded', 'moved'])
		expect(Object.keys(column('outcome').enumLabels)).toEqual(filters.slice(1).map((tab) => tab.filter.outcome))
	})

	it('names the rule that junked a message under its reason', () => {
		expect(column('reason').secondary).toBe('{junkReason}')
		expect(column('decided').label).toBe('Deciding filter')
	})

	it('says the order the filters ran in', () => {
		expect(logPage().config.sourceConfig).toMatchObject({ notePath: 'filterOrder', noteText: 'Filters run in this order: {order}' })
		expect(nl['Filters run in this order: {order}']).toBeTruthy()
	})

	it('does not read an empty log as a mailbox that filtered something', () => {
		expect(logPage().config.emptyText).toContain('filtered before it reached the folder')
	})

	it('offers release on a held message and on nothing else', () => {
		const release = actions().release

		expect(release.visibleWhen).toEqual({ field: 'outcome', op: 'eq', value: 'quarantined' })
		expect(release).toMatchObject({ type: 'open-modal', target: 'CnActionDialog' })
		expect(release.props.config.confirm.request).toEqual({ method: 'POST', url: '/apps/dossiq/api/mail-intake/log/@objectId/release' })
	})

	it('lets a person correct a wrong junk verdict', () => {
		const notJunk = actions()['not-junk']

		expect(notJunk.visibleWhen).toEqual({ field: 'junkRule', op: 'notEmpty' })
		expect(notJunk.props.config.confirm.request).toEqual({ method: 'POST', url: '/apps/dossiq/api/mail-intake/log/@objectId/junk', body: { junk: false } })
	})

	// 🔴 UNLIKE RELEASE, THIS ONE IS ON EVERY ROW, and that is the point of it.
	// A wrong match is the common reason somebody reaches for this, and an
	// entry that became 2026-090 when it belonged on 2026-114 looks exactly
	// like a successful match until a person reads it.
	// @spec openspec/specs/case-email-integration/spec.md
	it('offers File on a case on every entry, matched or not', () => {
		const file = actions()['file-on-case']

		expect(file).toMatchObject({ type: 'open-modal', target: 'MailIntakeFileOnCaseDialog' })
		expect(file.visibleWhen).toBeUndefined()
		expect(column('actions').widgetProps.actions).toEqual(['release', 'not-junk', 'file-on-case'])
		expect(registrySource).toMatch(/MailIntakeFileOnCaseDialog: \{\n\t\tkind: 'modal'/)
	})
})
