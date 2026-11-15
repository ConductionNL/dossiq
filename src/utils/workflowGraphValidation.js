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
 *
 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation
 */
export function validateWorkflowGraph({ statusNodes, transitions } = {}) {
	const nodes = asArray(statusNodes)
	const edges = asArray(transitions)

	if (nodes.length === 0) {
		// Nothing to validate yet — an empty canvas is incomplete, not invalid.
		return []
	}

	const graph = workflowGraph(nodes, edges)
	return [
		...danglingEdgeIssues(graph, edges),
		...duplicateTransitionIssues(graph),
		...noFinalStatusIssues(graph),
		...orphanNodeIssues(graph),
		...unreachableFinalIssues(graph),
		...cycleNoExitIssues(graph),
	]
}

/**
 * The graph the rules read: the nodes, the transitions between two of them,
 * the final nodes, and the forward and reverse adjacency over those
 * transitions.
 *
 * @param {Array<object>} nodes The `statusType` objects
 * @param {Array<object>} edges The transitions
 * @return {object} The graph
 */
function workflowGraph(nodes, edges) {
	const nodeIds = new Set(nodes.map((n) => n.id))
	const validEdges = edges.filter((edge) => nodeIds.has(edge.fromStatus) && nodeIds.has(edge.toStatus))
	const forward = new Map(nodes.map((n) => [n.id, []]))
	const reverse = new Map(nodes.map((n) => [n.id, []]))
	validEdges.forEach((edge) => {
		forward.get(edge.fromStatus).push(edge.toStatus)
		reverse.get(edge.toStatus).push(edge.fromStatus)
	})
	return {
		nodes,
		nodeIds,
		edges: validEdges,
		finalNodes: nodes.filter((n) => n.isFinal === true),
		forward,
		reverse,
		nodeName: (id) => nodes.find((n) => n.id === id)?.name || id,
	}
}

/**
 * DANGLING_EDGE: a transition references a status outside this graph.
 *
 * @param {object} graph The graph
 * @param {Array<object>} edges Every transition, valid or not
 * @return {Array<object>} The issues
 */
function danglingEdgeIssues(graph, edges) {
	return edges
		.filter((edge) => !graph.nodeIds.has(edge.fromStatus) || !graph.nodeIds.has(edge.toStatus))
		.map((edge) => ({
			type: 'error',
			code: RULES.DANGLING_EDGE,
			message: t(
				'dossiq',
				'Transition "{label}" references a status that no longer exists',
				{ label: edge.label || `${edge.fromStatus} → ${edge.toStatus}` },
			),
		}))
}

/**
 * DUPLICATE_TRANSITION: two transitions with the same from/to pair.
 *
 * @param {object} graph The graph
 * @return {Array<object>} The issues
 */
function duplicateTransitionIssues(graph) {
	const issues = []
	const seenPairs = new Set()
	graph.edges.forEach((edge) => {
		const key = `${edge.fromStatus}::${edge.toStatus}`
		if (seenPairs.has(key)) {
			issues.push({
				type: 'warning',
				code: RULES.DUPLICATE_TRANSITION,
				message: t(
					'dossiq',
					'Duplicate transition from "{from}" to "{to}"',
					{ from: graph.nodeName(edge.fromStatus), to: graph.nodeName(edge.toStatus) },
				),
			})
		}
		seenPairs.add(key)
	})
	return issues
}

/**
 * NO_FINAL_STATUS: at least one statusType must be marked final.
 *
 * @param {object} graph The graph
 * @return {Array<object>} The issues
 */
function noFinalStatusIssues(graph) {
	if (graph.finalNodes.length > 0) {
		return []
	}
	return [{
		type: 'error',
		code: RULES.NO_FINAL_STATUS,
		message: t('dossiq', 'Workflow has no final status defined'),
	}]
}

/**
 * ORPHAN_NODE: an isolated status (no incoming or outgoing transitions), in
 * a graph of more than one status.
 *
 * @param {object} graph The graph
 * @return {Array<object>} The issues
 */
function orphanNodeIssues(graph) {
	if (graph.nodes.length <= 1) {
		return []
	}
	return graph.nodes
		.filter((n) => graph.reverse.get(n.id).length === 0 && graph.forward.get(n.id).length === 0)
		.map((n) => ({
			type: 'warning',
			code: RULES.ORPHAN_NODE,
			message: t(
				'dossiq',
				'Status "{name}" has no transitions connecting it to the rest of the workflow',
				{ name: n.name },
			),
		}))
}

/**
 * UNREACHABLE_FINAL: a final status no starting status leads to.
 *
 * @param {object} graph The graph
 * @return {Array<object>} The issues
 */
function unreachableFinalIssues(graph) {
	const reachable = reachableFromStart(graph)
	return graph.finalNodes
		.filter((n) => !reachable.has(n.id))
		.map((n) => ({
			type: 'error',
			code: RULES.UNREACHABLE_FINAL,
			message: t(
				'dossiq',
				'Final status "{name}" cannot be reached from any starting status',
				{ name: n.name },
			),
		}))
}

/**
 * The statuses a breadth-first walk forward from the "initial" nodes (no
 * incoming transitions) visits. If every node has an incoming transition
 * (e.g. the whole graph is a cycle), there is no clear entry point, so
 * nothing is reachable.
 *
 * @param {object} graph The graph
 * @return {Set<string>} The reachable status ids
 */
function reachableFromStart(graph) {
	const reachable = new Set()
	const queue = graph.nodes.filter((n) => graph.reverse.get(n.id).length === 0).map((n) => n.id)
	while (queue.length > 0) {
		const current = queue.shift()
		if (reachable.has(current)) continue
		reachable.add(current)
		graph.forward.get(current).forEach((next) => queue.push(next))
	}
	return reachable
}

/**
 * The statuses that can reach a final status: a breadth-first walk over the
 * REVERSED graph, starting from the final nodes.
 *
 * @param {object} graph The graph
 * @return {Set<string>} The status ids
 */
function canReachFinal(graph) {
	const reaching = new Set(graph.finalNodes.map((n) => n.id))
	const queue = [...reaching]
	while (queue.length > 0) {
		const current = queue.shift()
		graph.reverse.get(current).forEach((prev) => {
			if (!reaching.has(prev)) {
				reaching.add(prev)
				queue.push(prev)
			}
		})
	}
	return reaching
}

const WHITE = 0
const GRAY = 1
const BLACK = 2

/**
 * CYCLE_NO_EXIT: a cycle whose member statuses can never reach a final
 * status, each distinct cycle once.
 *
 * @param {object} graph The graph
 * @return {Array<object>} The issues
 */
function cycleNoExitIssues(graph) {
	const reaching = canReachFinal(graph)
	const reported = new Set()
	const issues = []
	for (const cycleNodes of cyclesOf(graph)) {
		const cycleKey = [...cycleNodes].sort().join(',')
		if (reported.has(cycleKey) || cycleNodes.some((id) => reaching.has(id))) {
			continue
		}
		reported.add(cycleKey)
		issues.push(cycleNoExitIssue(graph, cycleNodes))
	}
	return issues
}

/**
 * The issue for one cycle without an exit.
 *
 * @param {object} graph The graph
 * @param {Array<string>} cycleNodes The statuses on the cycle
 * @return {object} The issue
 */
function cycleNoExitIssue(graph, cycleNodes) {
	return {
		type: 'warning',
		code: RULES.CYCLE_NO_EXIT,
		message: t(
			'dossiq',
			'Cycle detected with no exit to a final status: {names}',
			{ names: cycleNodes.map(graph.nodeName).join(' → ') },
		),
	}
}

/**
 * Every cycle a depth-first walk (white/gray/black) finds, as the statuses
 * on the stack from the one a back edge returns to, in the order found.
 *
 * @param {object} graph The graph
 * @return {Array<Array<string>>} The cycles
 */
function cyclesOf(graph) {
	const walk = {
		graph,
		color: new Map(graph.nodes.map((n) => [n.id, WHITE])),
		stack: [],
		cycles: [],
	}
	graph.nodes.forEach((n) => {
		if (walk.color.get(n.id) === WHITE) visit(walk, n.id)
	})
	return walk.cycles
}

/**
 * Visit one status of the depth-first walk.
 *
 * @param {object} walk The walk: graph, colours, stack and cycles found
 * @param {string} nodeId Node to visit
 * @return {void}
 */
function visit(walk, nodeId) {
	walk.color.set(nodeId, GRAY)
	walk.stack.push(nodeId)
	walk.graph.forward.get(nodeId).forEach((next) => {
		if (walk.color.get(next) === GRAY) {
			// Back edge found — the cycle is the stack from that node on.
			walk.cycles.push(walk.stack.slice(walk.stack.indexOf(next)))
		} else if (walk.color.get(next) === WHITE) {
			visit(walk, next)
		}
	})
	walk.stack.pop()
	walk.color.set(nodeId, BLACK)
}
