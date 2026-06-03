// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Frontend validator for the visual workflow editor.
//
// This is a *frontend* mirror of the canonical workflow-definition-model
// rules. It produces overlay issues for the canvas while the user edits.
// The authoritative validator lives in the workflow store
// (`validateWorkflow`); the publish action re-runs that one server-side.
//
// Issue shape: { nodeId?: string, edgeId?: string, level: 'error'|'warning', code, message }

/**
 * Resolve the human-readable id used by vue-flow for a working-copy step.
 *
 * @param {object} step Working copy step
 * @param {number} idx  Index in the steps array
 * @return {string}     Stable graph id
 */
function stepId(step, idx) {
	return step.id || step.status || `step-${idx}`
}

/**
 * Resolve the graph id of an edge derived from a workflow transition.
 *
 * @param {object} tr  Working copy transition
 * @param {number} idx Index in the transitions array
 * @return {string}    Stable edge id
 */
function edgeId(tr, idx) {
	return tr.id || `edge-${idx}`
}

/**
 * Run the full validation pass over a working copy.
 *
 * Codes:
 *   - NO_FINAL_STATUS      error   No step has isFinal === true.
 *   - UNREACHABLE_FINAL    error   A final step is not reachable from any initial.
 *   - ORPHAN_NODE          warning Step has no incoming and no outgoing transition.
 *   - DANGLING_EDGE        error   Edge references a missing fromStatus/toStatus.
 *   - DUPLICATE_TRANSITION warning Two transitions share the same (from, to) pair.
 *   - MISSING_LABEL        warning Transition has no label.
 *   - CYCLE_NO_EXIT        warning Cycle exists with no path to any final step.
 *
 * @param {object} workingCopy Working copy with `steps` + `transitions`
 * @return {Array<object>}     Issue list
 */
export function validateGraph(workingCopy) {
	const issues = []
	const steps = (workingCopy && workingCopy.steps) || []
	const transitions = (workingCopy && workingCopy.transitions) || []

	if (!steps.length && !transitions.length) {
		return issues
	}

	const idByIndex = new Map()
	steps.forEach((step, idx) => idByIndex.set(idx, stepId(step, idx)))
	const allIds = new Set([...idByIndex.values()])

	// NO_FINAL_STATUS
	const finalIds = new Set()
	steps.forEach((step, idx) => {
		if (step.isFinal) finalIds.add(stepId(step, idx))
	})
	if (!finalIds.size) {
		issues.push({
			level: 'error',
			code: 'NO_FINAL_STATUS',
			message: 'Workflow has no final status.',
		})
	}

	// Build adjacency + reverse adjacency
	const outgoing = new Map()
	const incoming = new Map()
	transitions.forEach((tr, idx) => {
		const eid = edgeId(tr, idx)

		if (!tr.fromStatus || !tr.toStatus) {
			issues.push({
				edgeId: eid,
				level: 'error',
				code: 'DANGLING_EDGE',
				message: 'Transition is missing fromStatus or toStatus.',
			})
			return
		}

		if (!allIds.has(tr.fromStatus) || !allIds.has(tr.toStatus)) {
			issues.push({
				edgeId: eid,
				level: 'error',
				code: 'DANGLING_EDGE',
				message: 'Transition references unknown status node.',
			})
			return
		}

		if (!outgoing.has(tr.fromStatus)) outgoing.set(tr.fromStatus, [])
		outgoing.get(tr.fromStatus).push(tr.toStatus)

		if (!incoming.has(tr.toStatus)) incoming.set(tr.toStatus, [])
		incoming.get(tr.toStatus).push(tr.fromStatus)

		if (!tr.label) {
			issues.push({
				edgeId: eid,
				level: 'warning',
				code: 'MISSING_LABEL',
				message: 'Transition has no label.',
			})
		}
	})

	// DUPLICATE_TRANSITION
	const seenPairs = new Map()
	transitions.forEach((tr, idx) => {
		if (!tr.fromStatus || !tr.toStatus) return
		const key = `${tr.fromStatus}→${tr.toStatus}`
		const eid = edgeId(tr, idx)
		if (seenPairs.has(key)) {
			issues.push({
				edgeId: eid,
				level: 'warning',
				code: 'DUPLICATE_TRANSITION',
				message: 'Duplicate transition between the same nodes.',
			})
		} else {
			seenPairs.set(key, eid)
		}
	})

	// ORPHAN_NODE
	steps.forEach((step, idx) => {
		const id = stepId(step, idx)
		const noIncoming = !incoming.has(id) || incoming.get(id).length === 0
		const noOutgoing = !outgoing.has(id) || outgoing.get(id).length === 0
		if (noIncoming && noOutgoing) {
			issues.push({
				nodeId: id,
				level: 'warning',
				code: 'ORPHAN_NODE',
				message: 'Status node is disconnected.',
			})
		}
	})

	// UNREACHABLE_FINAL — BFS from each initial (no incoming) and union reachable.
	const initials = [...allIds].filter((id) => !incoming.has(id) || incoming.get(id).length === 0)
	const reachable = new Set()
	const queue = [...initials]
	while (queue.length) {
		const cur = queue.shift()
		if (reachable.has(cur)) continue
		reachable.add(cur)
		const outs = outgoing.get(cur) || []
		for (const next of outs) {
			if (!reachable.has(next)) queue.push(next)
		}
	}
	for (const finalId of finalIds) {
		if (!reachable.has(finalId)) {
			issues.push({
				nodeId: finalId,
				level: 'error',
				code: 'UNREACHABLE_FINAL',
				message: 'Final status is not reachable from any initial node.',
			})
		}
	}

	// CYCLE_NO_EXIT — strongly-connected component lite: any node on a cycle
	// with no path to any final.
	if (finalIds.size) {
		const reachesFinal = new Set([...finalIds])
		let changed = true
		while (changed) {
			changed = false
			for (const id of allIds) {
				if (reachesFinal.has(id)) continue
				const outs = outgoing.get(id) || []
				if (outs.some((o) => reachesFinal.has(o))) {
					reachesFinal.add(id)
					changed = true
				}
			}
		}
		for (const id of allIds) {
			if (!reachesFinal.has(id) && (outgoing.get(id) || []).length > 0) {
				issues.push({
					nodeId: id,
					level: 'warning',
					code: 'CYCLE_NO_EXIT',
					message: 'Node is part of a cycle with no exit to a final status.',
				})
			}
		}
	}

	return issues
}
