/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case declares how each of its fields is searched, and a refused term
 * says where it broke.
 *
 * WHY THIS NEEDS A REAL STORE. The declarations are pinned to the byte by
 * `tests/vitest/caseSearchDeclarations.spec.js`, and the refusal reader by
 * `tests/vitest/searchRefusal.spec.js`. What neither can show is that the
 * declaration REACHES the query: `matchType` is read by openregister's
 * `PropertySearchProfile` out of the imported schema, and an import that never
 * ran leaves every property silent while the register JSON on disk reads
 * perfectly. A search that behaves the old way is the only evidence.
 *
 * 🔴 THE NEGATIVE IS THE WHOLE TEST. "Searching the full identifier finds the
 * case" passes just as well with `fulltext`, which is what it did before. The
 * assertion that separates the two is that HALF an identifier no longer finds
 * it, while half a word in a description still does. A spec that only asserts
 * the positive cannot fail.
 *
 * 🔴 AND A REFUSAL IS NOT AN EMPTY LIST. openregister answers 400 so a
 * malformed term is never run as a literal, because a literal returns zero
 * rows and reads as an honest empty result. The store swallows that into `[]`,
 * so the assertion below is that the page says something, not that it lists
 * nothing.
 *
 * ASSERT IDS AND SEEDED TEXT, NOT LABELS: nothing forces the language of the
 * e2e instance, so the only text asserted is text this fixture seeded, which
 * carries RUN_PREFIX and reads the same in either locale.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** The objects endpoint the list itself reads. */
const OBJECTS = '/index.php/apps/openregister/api/objects'

/** The case type every seeded case takes. */
let caseType = ''

/** The identifier only one case carries, and only in full. */
const IDENTIFIER = `${RUN_PREFIX}-SRCH-7781`

/** The half of it that must stop finding that case. */
const HALF_IDENTIFIER = IDENTIFIER.slice(0, IDENTIFIER.length - 2)

/** The case that carries the identifier. */
let byIdentifier = ''

/** The case that merely mentions it in prose. */
let byDescription = ''

/** A closed case that recorded its result. */
let closedWithResult = ''

/** A closed case that recorded none, which is the lens. */
let closedWithoutResult = ''

test.describe('The case declares how each of its fields is searched', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		caseType = (await ensureCaseType(api, token)).id

		byIdentifier = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} dakkapel Dorpsstraat`,
				caseType,
				identifier: IDENTIFIER,
				description: 'A roof extension on a listed street.',
			}),
		)

		byDescription = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} verwijzing`,
				caseType,
				description: `See ${IDENTIFIER} for the original application.`,
			}),
		)

		const result = objectId(
			await createObject(api, token, 'result', {
				title: `${RUN_PREFIX} Toegekend`,
				description: 'Granted, seeded by the search declarations suite.',
			}),
		)

		closedWithResult = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} afgehandeld met resultaat`,
				caseType,
				result,
			}),
		)

		closedWithoutResult = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} afgehandeld zonder resultaat`,
				caseType,
			}),
		)

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		await cleanupRunObjects(api, await getRequestToken(api))
		await api.dispose()
	})

	test('the identifier answers to the whole case number and not to half of it', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const whole = await api.get(
			`${OBJECTS}/${REGISTER}/case?_search=${encodeURIComponent(IDENTIFIER)}&_limit=200`,
		)
		expect(whole.ok(), `whole identifier -> ${whole.status()}`).toBeTruthy()
		const wholeIds = (await whole.json()).results.map((row: any) => String(row.id ?? row['@self']?.id))

		expect(wholeIds).toContain(byIdentifier)
		expect(wholeIds).toContain(byDescription)

		// The declaration, stated as the thing it forbids.
		const half = await api.get(
			`${OBJECTS}/${REGISTER}/case?_search=${encodeURIComponent(HALF_IDENTIFIER)}&_limit=200`,
		)
		expect(half.ok(), `half identifier -> ${half.status()}`).toBeTruthy()
		const halfIds = (await half.json()).results.map((row: any) => String(row.id ?? row['@self']?.id))

		expect(halfIds).not.toContain(byIdentifier)
		expect(halfIds).toContain(byDescription)

		await api.dispose()
	})

	test('a malformed term is refused rather than answered with nothing', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const refused = await api.get(
			`${OBJECTS}/${REGISTER}/case?_search=${encodeURIComponent('(dakkapel AND NOT geweigerd')}`,
		)

		expect(refused.status(), 'a malformed term is a refusal, not an empty page').toBe(400)

		const body = await refused.json()
		expect(typeof body.position).toBe('number')
		expect(String(body.term)).toContain('dakkapel')

		await api.dispose()
	})

	test('a plain term reaches openregister as typed', async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })

		const plain = await api.get(
			`${OBJECTS}/${REGISTER}/case?_search=${encodeURIComponent(`${RUN_PREFIX} dakkapel`)}&_limit=200`,
		)

		expect(plain.ok(), `plain term -> ${plain.status()}`).toBeTruthy()
		const ids = (await plain.json()).results.map((row: any) => String(row.id ?? row['@self']?.id))
		expect(ids).toContain(byIdentifier)

		await api.dispose()
	})

	test('the Cases page says where a refused term broke', async ({ page }) => {
		await page.goto(
			`/index.php/apps/dossiq/cases?_search=${encodeURIComponent('(dakkapel AND NOT geweigerd')}`,
			PAGE_LOAD,
		)

		const hint = page.getByTestId('case-search-refusal')
		await expect(hint).toBeVisible(PAGE_LOAD)
		await expect(page.getByTestId('case-search-refusal-term')).toContainText('dakkapel')
	})

	test('the Cases page shows no hint for a term it could read', async ({ page }) => {
		await page.goto(
			`/index.php/apps/dossiq/cases?_search=${encodeURIComponent(RUN_PREFIX)}`,
			PAGE_LOAD,
		)

		await expect(page.getByTestId('case-search-refusal')).toHaveCount(0)
	})

	test('the closed cases with no result are one lens away', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		// The lens as the manifest spells it, so a rename of the operator
		// suffix fails here rather than quietly widening the lens.
		//
		// Only the `result_isnull` half is asserted. `isFinalStatus` is
		// readOnly, computed by openregister from the linked statusType, so a
		// fixture cannot seed it without a whole state machine, and it is an
		// ordinary boolean filter that nothing in this change touches. The
		// half that depends on this change is the null grammar.
		const missing = await api.get(
			`${OBJECTS}/${REGISTER}/case?result_isnull=true&_limit=200`,
		)
		expect(missing.ok(), `result_isnull -> ${missing.status()}`).toBeTruthy()
		const ids = (await missing.json()).results.map((row: any) => String(row.id ?? row['@self']?.id))

		expect(ids).toContain(closedWithoutResult)
		expect(ids).not.toContain(closedWithResult)

		await api.dispose()
	})
})
