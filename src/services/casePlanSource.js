/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which engine answers for one case's plan, during the bridge release.
 *
 * Two runtimes hold a case plan right now: OpenRegister's case layer, which is
 * where every plan is going, and dossiq's own CMMN engine, which still holds
 * the plans of cases created before the bridge landed. This module is the one
 * place that decides between them, so the rule is written once and can be
 * turned off in one place.
 *
 * The rule, in full:
 *
 *  - OpenRegister has rows for this case: read OpenRegister. Always, even when
 *    a `casePlanState` blob is also present, because the migration clears the
 *    blob only AFTER it has verified the rows and a case caught mid-drain must
 *    read the half it has already committed.
 *  - OpenRegister has no rows and a blob is present: read the local engine.
 *    This is the only reason the engine still ships.
 *  - Neither: there is no plan. That is a caseType without a published
 *    caseModel, and it is not an error.
 *
 * The flag exists for one job, named in design.md section 4 as the R1
 * rollback: stop preferring rows, then run `occ dossiq:cmmn:rollback-case-plans`
 * where a blob is wanted back. With the flag off, a case that has both reads
 * the engine again.
 *
 * NOT an error path. An unreachable OpenRegister is `hasOpenRegisterRows:
 * false` here and the panel's own fail-closed rule handles it, because a
 * silent fall back to the local engine during an outage is how two runtimes
 * quietly disagree about one case.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
import { loadState } from '@nextcloud/initial-state'

/** The app-config key, and the initial-state key it is served under. */
export const PREFER_OPENREGISTER_KEY = 'cmmn_prefer_openregister_case_plan'

/** Read OpenRegister. */
export const SOURCE_OPENREGISTER = 'openregister'

/** Read dossiq's retiring CMMN engine. */
export const SOURCE_LOCAL = 'local'

/** Neither runtime holds a plan for this case. */
export const SOURCE_NONE = 'none'

/**
 * Whether this instance prefers OpenRegister rows. Defaults to yes.
 *
 * @return {boolean} True when rows win over the blob.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
export function prefersOpenRegister() {
	return loadState('dossiq', PREFER_OPENREGISTER_KEY, true) !== false
}

/**
 * Decide which runtime answers for one case.
 *
 * @param {object}  facts                     What is true about this case.
 * @param {boolean} facts.hasOpenRegisterRows Whether OpenRegister holds at least one plan item.
 * @param {boolean} facts.hasLocalBlob        Whether the case still carries a `casePlanState` blob.
 * @param {boolean} facts.preferOpenRegister  Whether this instance prefers rows.
 * @return {string} `openregister`, `local` or `none`.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
export function decidePlanSource({
	hasOpenRegisterRows = false,
	hasLocalBlob = false,
	preferOpenRegister = true,
} = {}) {
	if (hasOpenRegisterRows && preferOpenRegister) {
		return SOURCE_OPENREGISTER
	}

	if (hasLocalBlob) {
		return SOURCE_LOCAL
	}

	if (hasOpenRegisterRows) {
		return SOURCE_OPENREGISTER
	}

	return SOURCE_NONE
}

/**
 * Whether a case object still carries a runtime blob.
 *
 * An empty string, an empty object and the JSON text of an empty object all
 * mean "drained". The migration clears the blob by writing an empty value, so
 * a cleared case must not keep reading the engine.
 *
 * @param {object} caseObject The case record.
 * @return {boolean} True when a blob is present.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-004-the-blob-retires-after-the-drain-not-before
 */
export function hasLocalPlanBlob(caseObject) {
	const blob = caseObject?.casePlanState

	if (blob === null || blob === undefined || blob === '') {
		return false
	}

	if (typeof blob === 'string') {
		const trimmed = blob.trim()
		return trimmed !== '' && trimmed !== '{}' && trimmed !== '[]' && trimmed !== 'null'
	}

	if (typeof blob === 'object') {
		return Object.keys(blob).length > 0
	}

	return false
}

/**
 * Put the retiring engine's plan items into the shape the panel renders.
 *
 * The engine answers `{id, type, name, discretionary, parentId, state}` with
 * `parentId` naming another item's string id; OpenRegister answers rows keyed
 * on a numeric id with `parentItemId`. Normalising here means the panel has one
 * renderer and `groupPlanByStage` has one input shape.
 *
 * @param {Array<object>} items The engine's flat item list.
 * @return {Array<object>} Items in the OpenRegister row shape.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
 */
export function normaliseLocalPlanItems(items) {
	return (Array.isArray(items) ? items : []).map((item, index) => ({
		id: item.id,
		uuid: item.id,
		key: item.id,
		name: item.name,
		type: item.type,
		state: item.state,
		discretionary: item.discretionary === true,
		parentItemId: item.parentId ?? null,
		position: index,
	}))
}
