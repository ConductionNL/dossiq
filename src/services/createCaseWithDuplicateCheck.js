/**
 * File a case, after asking whether one like it already exists.
 *
 * Registered as the `new-case` action's `createOverride`, so it owns the persist
 * where `objectStore.saveObject` used to. That seam is the one the library
 * offers: the create dialog is `CnAdvancedFormDialog`, mounted by
 * `CnActionButtons`, and dossiq cannot reach inside it to disable its Create
 * button or to draw a panel between its fields.
 *
 * 🔴 SO THE WARNING LANDS ON THE PRESS, NOT ON THE KEYSTROKE, AND THE SPEC ASKS
 * FOR THE KEYSTROKE. What the handler gets is the same decision at the same
 * moment: the case does not exist yet, the matches are named, and Cancel goes
 * back to the form with everything still typed. What they do not get is a Create
 * button that greys out while they type. Closing that gap is a
 * `@conduction/nextcloud-vue` change (a `beforeConfirm` hook on the form dialog),
 * not a dossiq one, and it is filed as such rather than worked around here with
 * a second form dossiq would then have to keep in step with the manifest.
 *
 * Nothing is compared here. OpenRegister scores the candidate, dossiq's own
 * `DuplicatePolicy` says what the case type does about it, and this function
 * renders the two answers and carries the handler's decision into the write.
 * The write is refused server-side when it should be, so a caller that skips
 * this path entirely is refused all the same.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */

import { useObjectStore } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import DuplicateWarningModal from '../modals/DuplicateWarningModal.vue'
import { affordancesFor } from '../utils/duplicateWarning.js'
import { normaliseDeclaration } from '../utils/intakeRequirements.js'
import { checkForDuplicates, fetchMatchedCases } from './duplicateCheckApi.js'
import { fetchIntakeRequirements } from './intakeTriageApi.js'

/** The case field carrying why a case was filed over a warning. */
export const FIELD_REASON = 'duplicateOverrideReason'

/** The case field listing what it was filed over. */
export const FIELD_OVER = 'duplicateOverrideOf'

/**
 * Ask the handler what to do about the matches.
 *
 * Resolves with the decision, or with null when they cancelled. Mounted the way
 * `customComponents.js` mounts its bulk dialogs, for the same reason: the
 * library's declarative modal path emits `open-modal` and nothing consumes it.
 *
 * @param {object} state What to show.
 * @param {Array<object>} state.matches The scored matches.
 * @param {Array<object>} state.cases The matched cases that could be read.
 * @param {string} state.policy What the case type declared.
 * @param {boolean} state.mayOverride Whether this account may file anyway.
 * @return {Promise<{reason: string, over: Array<string>}|null>} The decision, or null.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export function askAboutDuplicates(state) {
	return new Promise((resolve) => {
		const host = document.createElement('div')
		document.body.appendChild(host)

		let app = null

		/**
		 * Tear the mounted modal down and answer once.
		 *
		 * @param {object|null} decision What the handler chose.
		 * @return {void}
		 */
		function close(decision) {
			app.unmount()
			host.remove()
			resolve(decision)
		}

		app = createApp(DuplicateWarningModal, {
			matches: state.matches,
			cases: state.cases,
			policy: state.policy,
			mayOverride: state.mayOverride,
			onClose: () => close(null),
			onFile: (decision) => close(decision),
		})
		app.mount(host)
	})
}

/**
 * What the case type says about duplicates, for the case type just chosen.
 *
 * Reuses the intake requirements read the create flow already has, so choosing
 * a case type asks one question rather than two. A read that failed answers the
 * default, which is `warn`: the write path is what refuses, and it does not
 * depend on this call.
 *
 * @param {string} caseTypeId The chosen case type.
 * @return {Promise<{policy: string, mayOverride: boolean}>} The declaration.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export async function duplicatePolicyFor(caseTypeId) {
	const declaration = normaliseDeclaration(
		await fetchIntakeRequirements(caseTypeId),
	)

	return {
		policy: declaration.duplicatePolicy,
		mayOverride: declaration.mayOverrideDuplicates,
	}
}

/**
 * The create-override itself.
 *
 * @param {object} payload The form's values.
 * @param {object} context The library's `{ register, schema, type }`.
 * @return {Promise<object>} The saved case.
 * @throws {Error} When the handler cancelled, so the dialog stays open.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export async function createCaseWithDuplicateCheck(payload, context) {
	// 🔴 THE LIBRARY'S STORE, NOT DOSSIQ'S. `CnActionButtons` resolved
	// `context.type` against `useObjectStore()` from
	// `@conduction/nextcloud-vue` (pinia id `conduction-objects`), and dossiq's
	// own store is a SECOND store under the id `object` with its own type
	// registry. Saving the library's type through dossiq's store is a lookup in
	// the wrong registry, which answers "not registered" rather than failing
	// somewhere a reader would look.
	const store = useObjectStore()
	const type = context?.type || 'case'
	const candidate = { ...payload }

	const [{ matches, checked }, policy] = await Promise.all([
		checkForDuplicates(candidate),
		duplicatePolicyFor(candidate.caseType),
	])

	const affordances = affordancesFor({
		matches,
		checked,
		policy: policy.policy,
		mayOverride: policy.mayOverride,
	})

	if (affordances.show === false) {
		return store.saveObject(type, candidate)
	}

	const cases = await fetchMatchedCases(matches.map((match) => match.uuid))
	const decision = await askAboutDuplicates({
		matches,
		cases,
		policy: policy.policy,
		mayOverride: policy.mayOverride,
	})

	if (decision === null) {
		// The library turns a throw into the dialog's error line and keeps the
		// form open with everything still typed, which is what Cancel means
		// here: go back and look at the case that already exists.
		throw new Error(t('dossiq', 'The case was not filed.'))
	}

	if (decision.reason !== '') {
		candidate[FIELD_REASON] = decision.reason
	}

	candidate[FIELD_OVER] = decision.over

	return store.saveObject(type, candidate)
}
