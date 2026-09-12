/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which runtime answers for one case's plan, during the bridge release.
 *
 * Both directions of the rule are pinned here, and they are the whole point of
 * the flag. Reading the local engine while OpenRegister has rows means two
 * runtimes disagree about a case that is already drained. Refusing to fall
 * back while a blob is present means every case created before the bridge
 * landed shows an empty plan, which is the worse of the two because it looks
 * like the case has no work left.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app, _key, fallback) => fallback,
}))

const importSource = async () => await import('../../src/services/casePlanSource.js')

describe('decidePlanSource', () => {
	it('reads OpenRegister when it has rows, even though a blob is still present', async () => {
		const { decidePlanSource } = await importSource()

		expect(
			decidePlanSource({ hasOpenRegisterRows: true, hasLocalBlob: true, preferOpenRegister: true }),
		).toBe('openregister')
	})

	it('falls back to the local engine only while a blob is present', async () => {
		const { decidePlanSource } = await importSource()

		expect(
			decidePlanSource({ hasOpenRegisterRows: false, hasLocalBlob: true, preferOpenRegister: true }),
		).toBe('local')
	})

	it('answers none when neither runtime holds a plan', async () => {
		const { decidePlanSource } = await importSource()

		expect(
			decidePlanSource({ hasOpenRegisterRows: false, hasLocalBlob: false, preferOpenRegister: true }),
		).toBe('none')
	})

	it('still reads OpenRegister with the flag off when there is no blob to go back to', async () => {
		const { decidePlanSource } = await importSource()

		expect(
			decidePlanSource({ hasOpenRegisterRows: true, hasLocalBlob: false, preferOpenRegister: false }),
		).toBe('openregister')
	})

	it('goes back to the engine with the flag off when the case has both, which is the R1 rollback', async () => {
		const { decidePlanSource } = await importSource()

		expect(
			decidePlanSource({ hasOpenRegisterRows: true, hasLocalBlob: true, preferOpenRegister: false }),
		).toBe('local')
	})

	it('answers none for a case it knows nothing about', async () => {
		const { decidePlanSource } = await importSource()
		expect(decidePlanSource()).toBe('none')
	})
})

describe('hasLocalPlanBlob', () => {
	it('is false for every shape the migration leaves behind when it clears one', async () => {
		const { hasLocalPlanBlob } = await importSource()

		for (const blob of ['', '  ', '{}', '[]', 'null', null, undefined, {}]) {
			expect(hasLocalPlanBlob({ casePlanState: blob })).toBe(false)
		}

		expect(hasLocalPlanBlob({})).toBe(false)
		expect(hasLocalPlanBlob(null)).toBe(false)
	})

	it('is true for a blob that still records item state', async () => {
		const { hasLocalPlanBlob } = await importSource()

		expect(hasLocalPlanBlob({ casePlanState: '{"planItemStates":{"intake":"active"}}' })).toBe(true)
		expect(hasLocalPlanBlob({ casePlanState: { planItemStates: { intake: 'active' } } })).toBe(true)
	})
})

describe('normaliseLocalPlanItems', () => {
	it('puts the engine\'s items into the row shape the panel renders', async () => {
		const { normaliseLocalPlanItems } = await importSource()

		expect(
			normaliseLocalPlanItems([
				{ id: 'intake', name: 'Intake', type: 'stage', parentId: null, state: 'active', discretionary: false },
				{ id: 'controle', name: 'Controle', type: 'humanTask', parentId: 'intake', state: 'enabled', discretionary: true },
			]),
		).toEqual([
			{ id: 'intake', uuid: 'intake', key: 'intake', name: 'Intake', type: 'stage', state: 'active', discretionary: false, parentItemId: null, position: 0 },
			{ id: 'controle', uuid: 'controle', key: 'controle', name: 'Controle', type: 'humanTask', state: 'enabled', discretionary: true, parentItemId: 'intake', position: 1 },
		])
	})

	it('answers an empty list for nothing', async () => {
		const { normaliseLocalPlanItems } = await importSource()
		expect(normaliseLocalPlanItems(undefined)).toEqual([])
	})
})

describe('prefersOpenRegister', () => {
	it('defaults to yes when the instance says nothing', async () => {
		const { prefersOpenRegister } = await importSource()
		expect(prefersOpenRegister()).toBe(true)
	})
})
