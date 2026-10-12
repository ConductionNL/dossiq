/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether OpenRegister's case layer could hold a plan for a case.
 *
 * One runtime holds a case plan: OpenRegister's case layer. dossiq's own CMMN
 * engine is gone (retire-cmmn-caseplanstate, group 3), so there is no second
 * source to choose and no fallback. What is left to decide is whether asking
 * OpenRegister is worth a request at all.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

/**
 * Whether OpenRegister could hold a plan for a case, before asking it.
 *
 * dossiq projects a plan onto OpenRegister only for a case whose caseType has
 * `handlingModel: cmmn` (CasePlanProjectionService::projectAtCaseStart, which
 * reads an absent handlingModel as `bpmn`). For every other case the read
 * could only answer 404, and it did, on every case page: a red request in the
 * console for a case that was never meant to have a plan.
 *
 * It answers "ask" whenever it is not SURE. A caseType that cannot be read
 * says nothing either way, so the panel still asks and its fail-closed rule
 * keeps seeing every outage.
 *
 * @param {object}      facts          What is known about the case.
 * @param {object|null} facts.caseType The case's caseType, or null when unread.
 * @return {boolean} False only when OpenRegister certainly holds no plan.
 * @spec openspec/changes/r6-dossiq-titles-related-cases-requests/specs/case-plan-read/spec.md
 */
export function mayHoldOpenRegisterPlan({ caseType = null } = {}) {
	if (caseType === null || typeof caseType !== 'object') {
		return true
	}
	return String(caseType.handlingModel ?? 'bpmn') === 'cmmn'
}
