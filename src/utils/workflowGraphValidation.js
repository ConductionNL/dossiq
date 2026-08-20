// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// Pure workflow-graph validation for the visual workflow editor
// (`WorkflowEditor.vue`). Operates on the REAL schema — `statusType`
// objects (with `isFinal`) as graph nodes and `workflowTemplate.transitions`
// as edges between them — unlike the deleted `src/components/workflow/
// validator.js` (from the dead `@vue-flow`-based duplicate editor), which
// modelled the graph on `step.isFinal`, a field that does not exist on the
// `step` schema (`isFinal` lives on `statusType`).
//
// Mirrors (client-side, for fast itemised feedback) the referential-
// integrity check `WorkflowDefinitionService::transitionsReferenceForeignStatuses()`
// enforces server-side at publish time (DANGLING_EDGE below) — the backend
// call remains the authoritative backstop; this util never replaces it.
//
// @spec openspec/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation

/** Validation rule codes. */
export const RULES = {
	NO_FINAL_STATUS: 'NO_FINAL_STATUS',
	UNREACHABLE_FINAL: 'UNREACHABLE_FINAL',
	DANGLING_EDGE: 'DANGLING_EDGE',
	DUPLICATE_TRANSITION: 'DUPLICATE_TRANSITION',
	ORPHAN_NODE: 'ORPHAN_NODE',
	CYCLE_NO_EXIT: 'CYCLE_NO_EXIT',
}

/**
 * Normalise a raw steps/transitions value that may arrive as a JSON string
 * (as stored on the `workflowTemplate` object) or an already-parsed array.
 *
 * @param {string|Array|null|undefined} raw Raw value
 * @return {Array} Parsed array, or [] on any parse failure
 */
function asArray(raw) {
	if (Array.isArray(raw)) return raw
	if (typeof raw === 'string') {
		try {
			const parsed = JSON.parse(raw)
			return Array.isArray(parsed) ? parsed : []
		} catch {
			return []
		}
	}
	return []
}

/**
 * Validate a workflow graph against the constraints the publish engine
 * actually enforces (plus editor-only UX rules: duplicate/orphan/cycle).
 *
 * @param {object} graph The graph to validate
 * @param {Array|string} graph.statusNodes `statusType` objects: {id, name, isFinal}
 * @param {Array|string} graph.transitions Transition objects: {id, fromStatus, toStatus}
 * @return {Array<{type: 'error'|'warning', code: string, message: string}>} Issues found
 */
export function validateWorkflowGraph({ statusNodes, transitions } = {}) {
	const nodes = asArray(statusNodes)
	const edges = asArray(transitions)
	const issues = []

	if (nodes.length === 0) {
		// Nothing to validate yet — an empty canvas is incomplete, not invalid.
		return issues
	}

	const nodeIds = new Set(nodes.map((n) => n.id))
	const nodeName = (id) => nodes.find((n) => n.id === id)?.name || id

	// --- DANGLING_EDGE: transition references a status outside this graph.
	const validEdges = []
	edges.forEach((edge) => {
		const fromValid = nodeIds.has(edge.fromStatus)
		const toValid = nodeIds.has(edge.toStatus)
		if (!fromValid || !toValid) {
			issues.push({
				type: 'error',
				code: RULES.DANGLING_EDGE,
				message: t(
					'procest',
					'Transition "{label}" references a status that no longer exists',
					{ label: edge.label || `${edge.fromStatus} → ${edge.toStatus}` },
				),
			})
			return
		}
		validEdges.push(edge)
	})

	// --- DUPLICATE_TRANSITION: two edges with the same from/to pair.
	const seenPairs = new Set()
	validEdges.forEach((edge) => {
		const key = `${edge.fromStatus}::${edge.toStatus}`
		if (seenPairs.has(key)) {
			issues.push({
				type: 'warning',
				code: RULES.DUPLICATE_TRANSITION,
				message: t(
					'procest',
					'Duplicate transition from "{from}" to "{to}"',
					{ from: nodeName(edge.fromStatus), to: nodeName(edge.toStatus) },
				),
			})
		}
		seenPairs.add(key)
	})

	// --- NO_FINAL_STATUS: at least one statusType must be marked final.
	const finalNodes = nodes.filter((n) => n.isFinal === true)
	if (finalNodes.length === 0) {
		issues.push({
			type: 'error',
			code: RULES.NO_FINAL_STATUS,
			message: t('procest', 'Workflow has no final status defined'),
		})
	}

	// Build forward/reverse adjacency over valid edges only.
	const forward = new Map(nodes.map((n) => [n.id, []]))
	const reverse = new Map(nodes.map((n) => [n.id, []]))
	validEdges.forEach((edge) => {
		forward.get(edge.fromStatus).push(edge.toStatus)
		reverse.get(edge.toStatus).push(edge.fromStatus)
	})

	// --- ORPHAN_NODE: isolated status (no incoming or outgoing transitions).
	if (nodes.length > 1) {
		nodes.forEach((n) => {
			const hasIn = reverse.get(n.id).length > 0
			const hasOut = forward.get(n.id).length > 0
			if (!hasIn && !hasOut) {
				issues.push({
					type: 'warning',
					code: RULES.ORPHAN_NODE,
					message: t(
						'procest',
						'Status "{name}" has no transitions connecting it to the rest of the workflow',
						{ name: n.name },
					),
				})
			}
		})
	}

	// --- UNREACHABLE_FINAL: BFS forward from "initial" nodes (no incoming
	// edges); any final node never visited is unreachable. If every node has
	// an incoming edge (e.g. the whole graph is a cycle), there is no clear
	// entry point, so nothing is reachable.
	const initialNodes = nodes.filter((n) => reverse.get(n.id).length === 0)
	const reachableFromInitial = new Set()
	const bfsQueue = [...initialNodes.map((n) => n.id)]
	while (bfsQueue.length > 0) {
		const current = bfsQueue.shift()
		if (reachableFromInitial.has(current)) continue
		reachableFromInitial.add(current)
		forward.get(current).forEach((next) => bfsQueue.push(next))
	}
	finalNodes.forEach((n) => {
		if (!reachableFromInitial.has(n.id)) {
			issues.push({
				type: 'error',
				code: RULES.UNREACHABLE_FINAL,
				message: t(
					'procest',
					'Final status "{name}" cannot be reached from any starting status',
					{ name: n.name },
				),
			})
		}
	})

	// --- CYCLE_NO_EXIT: a cycle whose member nodes can never reach a final
	// status. First compute canReachFinal via BFS over the REVERSED graph
	// starting from final nodes.
	const canReachFinal = new Set(finalNodes.map((n) => n.id))
	const revQueue = [...finalNodes.map((n) => n.id)]
	while (revQueue.length > 0) {
		const current = revQueue.shift()
		reverse.get(current).forEach((prev) => {
			if (!canReachFinal.has(prev)) {
				canReachFinal.add(prev)
				revQueue.push(prev)
			}
		})
	}

	// DFS cycle detection (white/gray/black) collecting the node set of each
	// distinct cycle found via a back-edge to a node still on the stack.
	const WHITE = 0
	const GRAY = 1
	const BLACK = 2
	const color = new Map(nodes.map((n) => [n.id, WHITE]))
	const stack = []
	const reportedCycles = new Set()

	/**
	 * @param {string} nodeId Node to visit
	 */
	function dfs(nodeId) {
		color.set(nodeId, GRAY)
		stack.push(nodeId)
		forward.get(nodeId).forEach((next) => {
			if (color.get(next) === GRAY) {
				// Back edge found — extract the cycle from the stack.
				const cycleStart = stack.indexOf(next)
				const cycleNodes = stack.slice(cycleStart)
				const cycleKey = [...cycleNodes].sort().join(',')
				if (
					!reportedCycles.has(cycleKey)
					&& !cycleNodes.some((id) => canReachFinal.has(id))
				) {
					reportedCycles.add(cycleKey)
					issues.push({
						type: 'warning',
						code: RULES.CYCLE_NO_EXIT,
						message: t(
							'procest',
							'Cycle detected with no exit to a final status: {names}',
							{ names: cycleNodes.map(nodeName).join(' → ') },
						),
					})
				}
			} else if (color.get(next) === WHITE) {
				dfs(next)
			}
		})
		stack.pop()
		color.set(nodeId, BLACK)
	}

	nodes.forEach((n) => {
		if (color.get(n.id) === WHITE) dfs(n.id)
	})

	return issues
}
