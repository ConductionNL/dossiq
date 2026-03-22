/**
 * Parafeer Engine — sequential step routing logic for B&W voorstel parafering.
 *
 * All functions are pure utilities that operate on voorstel and route data.
 * State mutations are performed by the calling component via the object store.
 */

/**
 * Parse the route snapshot from a voorstel.
 *
 * @param {object} voorstel The voorstel object
 * @return {Array} Ordered array of step objects
 */
export function getRouteSteps(voorstel) {
	if (!voorstel?.routeSnapshot) return []
	try {
		return typeof voorstel.routeSnapshot === 'string'
			? JSON.parse(voorstel.routeSnapshot)
			: voorstel.routeSnapshot
	} catch {
		return []
	}
}

/**
 * Get the current step info from a voorstel.
 *
 * @param {object} voorstel The voorstel object
 * @return {object|null} Current step or null
 */
export function getCurrentStep(voorstel) {
	const steps = getRouteSteps(voorstel)
	if (!steps.length || !voorstel.currentStep) return null
	return steps.find(s => s.order === voorstel.currentStep) || null
}

/**
 * Check if a user is the active actor at the current step.
 *
 * @param {object} voorstel The voorstel object
 * @param {string} userId   The Nextcloud user UID
 * @return {boolean}
 */
export function isActiveActor(voorstel, userId) {
	const step = getCurrentStep(voorstel)
	if (!step) return false
	return step.actor === userId
}

/**
 * Compute the next step number after a completed action.
 *
 * @param {object} voorstel The voorstel object
 * @return {number|null} Next step number, or null if this was the last step
 */
export function getNextStep(voorstel) {
	const steps = getRouteSteps(voorstel)
	const current = voorstel.currentStep || 0
	const next = steps.find(s => s.order > current)
	return next ? next.order : null
}

/**
 * Determine the new voorstel status after advancing to the next step.
 *
 * @param {object} voorstel The voorstel object
 * @return {string} New status ('in_parafering', 'ter_accordering', or 'geaccordeerd')
 */
export function getStatusAfterAdvance(voorstel) {
	const steps = getRouteSteps(voorstel)
	const nextStepNum = getNextStep(voorstel)

	if (nextStepNum === null) {
		return 'geaccordeerd'
	}

	const nextStep = steps.find(s => s.order === nextStepNum)
	if (nextStep?.type === 'accordering') {
		return 'ter_accordering'
	}

	return 'in_parafering'
}

/**
 * Create a route snapshot from a parafeerroute object.
 * Called when a voorstel is submitted for parafering.
 *
 * @param {object} route The parafeerroute object
 * @return {Array} Snapshot of steps
 */
export function createRouteSnapshot(route) {
	if (!route?.steps) return []
	const steps = typeof route.steps === 'string' ? JSON.parse(route.steps) : route.steps
	return steps.map(step => ({
		order: step.order,
		type: step.type,
		actor: step.actor,
		actorType: step.actorType || 'user',
		mandatory: step.mandatory !== false,
		label: step.label || '',
	}))
}

/**
 * Insert an ad-hoc step into a route snapshot at a given position.
 *
 * @param {Array}  steps    Current steps array
 * @param {number} afterOrder Insert after this step order number
 * @param {object} newStep   The new step to insert (without order)
 * @return {Array} Updated steps with renumbered orders
 */
export function insertAdHocStep(steps, afterOrder, newStep) {
	const result = []
	let orderCounter = 1

	for (const step of steps) {
		result.push({ ...step, order: orderCounter })
		orderCounter++

		if (step.order === afterOrder) {
			result.push({
				...newStep,
				order: orderCounter,
			})
			orderCounter++
		}
	}

	return result
}

/**
 * Mark a step as skipped in the route snapshot.
 *
 * @param {Array}  steps     Current steps array
 * @param {number} stepOrder The step order to skip
 * @return {Array} Updated steps with the step marked as skipped
 */
export function markStepSkipped(steps, stepOrder) {
	return steps.map(step => {
		if (step.order === stepOrder) {
			return { ...step, skipped: true }
		}
		return step
	})
}

/**
 * Find the default parafeerroute for a given case type and voorstel type.
 *
 * @param {Array}  routes      All parafeerroute objects
 * @param {string} caseTypeId  The case type UUID
 * @param {string} voorstelType The voorstel type (dt_advies, collegeadvies, raadsvoorstel)
 * @return {object|null} The matching default route, or null
 */
export function findDefaultRoute(routes, caseTypeId, voorstelType) {
	return routes.find(r =>
		r.isDefault === true
		&& r.caseType === caseTypeId
		&& r.voorstelType === voorstelType,
	) || routes.find(r =>
		r.isDefault === true
		&& r.voorstelType === voorstelType,
	) || null
}
