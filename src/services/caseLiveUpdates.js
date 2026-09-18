/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page subscribes to the case it is showing (gap register row 2.20).
 *
 * `openspec/specs/realtime-updates-ui/spec.md` has required this since it was
 * written, and the page did not do it: `liveUpdatesPlugin` was reached from
 * `WorkflowBoard` and `DeelzaakDetail` and from nowhere else, so the one page
 * a handler keeps open all day was the one that refreshed when they did.
 *
 * WHY THIS IS A ROUTER SUBSCRIPTION AND NOT A COMPONENT'S `mounted()`.
 * `#CaseDetail` is a manifest-driven page: `routesFromManifest` hands the route
 * to the library's `CnPageRenderer`, which resolves `type: "detail"` to
 * `CnDetailPage`. There is no dossiq component in that tree to hang a
 * subscription on. A widget could carry one, but a widget is mounted by
 * whichever tab happens to be open, so the subscription would come and go with
 * a panel rather than with the case. The route is the thing that actually
 * matches the lifetime of "this case is on screen", so the route is what drives
 * it.
 *
 * WHAT AN EVENT DOES. Nothing here patches state from a payload. An event is a
 * HINT, and `liveUpdatesPlugin` answers it by re-running `fetchObject('case',
 * id)` through the store's existing path, coalesced so a burst collapses into
 * one trailing refetch. The header, the panels and the terms read that store
 * entry, so they follow. That is the same contract `DeelzaakDetail` documents
 * at its own call site, and the reason neither of them ever reads the event
 * body: a payload applied locally is a second copy of the record that
 * eventually disagrees with the register.
 *
 * WHAT THIS DOES NOT COVER, said out loud. A FLOW RUN IS NOT AN OPENREGISTER
 * OBJECT: the engine keeps its runs in its own table behind
 * `/apps/openregister/api/flow-runs`, so no `or-object-*` event is ever emitted
 * for one and there is nothing here to subscribe to on their behalf. A run
 * advancing normally writes the case as well, which this subscription does
 * catch; the runs LIST itself is `CnFlowRunsWidget`'s own fetch and refreshes
 * when the widget mounts. Making that list live belongs to the two halves that
 * own it, openregister emitting for a run and nextcloud-vue refetching on the
 * hint, and not to a poll on this page.
 *
 * @spec openspec/changes/live-updates-on-the-case-page/specs/realtime-updates-ui/spec.md
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */

/** The manifest page whose route this subscribes for. */
export const CASE_PAGE_ROUTE = 'CaseDetail'

/**
 * The case id this route is showing, or an empty string when it shows none.
 *
 * Matched on the ROUTE NAME rather than on the presence of an `id` param.
 * Half the pages in this app carry an `id`, so a check for the param alone
 * would subscribe to a workflow definition, a bezwaar or a tenant as if it
 * were a case, and the subscription would silently be for an object the case
 * store cannot fetch.
 *
 * @param {object|null} route The resolved route.
 *
 * @return {string} The case id, or ''.
 * @spec openspec/changes/live-updates-on-the-case-page/specs/realtime-updates-ui/spec.md
 */
export function caseIdOfRoute(route) {
	if (route?.name !== CASE_PAGE_ROUTE) {
		return ''
	}
	return String(route?.params?.id || '')
}

/**
 * Keeps exactly one live subscription pointed at the case on screen.
 *
 * The shape is `DeelzaakDetail`'s, for the reasons its own comments give and
 * one more: `subscribe()` is asynchronous, so between asking and being handed a
 * handle the reader can have opened another case or left the page entirely. An
 * epoch counter is what makes that safe. Without it the late handle is stored
 * for a case nobody is looking at, and it keeps refetching until the tab
 * closes.
 *
 * @spec openspec/changes/live-updates-on-the-case-page/specs/realtime-updates-ui/spec.md
 */
export class CaseLiveSubscription {
	/**
	 * @param {object} store The object store (createObjectStore instance).
	 */
	constructor(store) {
		this.store = store
		this.handle = null
		this.key = ''
		this.pendingKey = ''
		this.epoch = 0
		this.lastError = ''
	}

	/**
	 * Point the subscription at this case, or release it when there is none.
	 *
	 * Re-entrant and idempotent: asked for the case it already holds, it does
	 * nothing at all, which is what makes it safe to call on every navigation.
	 *
	 * @param {string} caseId The case to subscribe to, or '' for none.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/live-updates-on-the-case-page/specs/realtime-updates-ui/spec.md
	 */
	async sync(caseId) {
		const store = this.store
		if (typeof store?.subscribe !== 'function') {
			// An older library, or a store built without the plugin. Doing
			// nothing is right: the page keeps working, it simply does not
			// refresh on its own.
			return
		}

		if (!caseId || !store.objectTypeRegistry?.case) {
			// `case` unregistered means the settings read has not landed or
			// this instance has no case schema configured. Subscribing anyway
			// throws inside the plugin, so release and wait to be called again.
			this.release()
			return
		}

		if (
			(this.handle !== null && this.key === caseId)
			|| this.pendingKey === caseId
		) {
			return
		}

		this.release()
		const epoch = this.epoch
		this.pendingKey = caseId
		this.key = caseId

		try {
			const handle = await store.subscribe('case', caseId)
			if (this.epoch !== epoch) {
				// Released while awaiting: another case was opened, or the
				// reader left the page. Drop the handle rather than storing it.
				store.unsubscribe(handle)
				return
			}
			this.handle = handle
		} catch (err) {
			this.handle = null
			this.key = ''
			// Recorded rather than printed, and never rethrown. A failed
			// subscription must not break the page: the case still renders,
			// it simply does not refresh on its own, and a navigation calls
			// sync() again so a transport that comes back is picked up.
			this.lastError = String(err?.message ?? err)
		} finally {
			if (this.pendingKey === caseId) {
				this.pendingKey = ''
			}
		}
	}

	/**
	 * Drop the subscription, and invalidate any subscribe still in flight.
	 *
	 * @return {void}
	 * @spec openspec/changes/live-updates-on-the-case-page/specs/realtime-updates-ui/spec.md
	 */
	release() {
		this.epoch = this.epoch + 1
		this.pendingKey = ''
		this.key = ''
		if (this.handle !== null) {
			try {
				this.store.unsubscribe(this.handle)
			} catch {
				// An unsubscribe that throws must not leave the handle set:
				// the next sync would then believe it still holds one.
			}
			this.handle = null
		}
	}
}

/**
 * Follow the router with one case subscription.
 *
 * @param {object} router The vue-router instance.
 * @param {() => object} storeFactory Resolves the object store, lazily.
 *
 * @return {CaseLiveSubscription} The subscription, so a caller can release it.
 * @spec openspec/changes/live-updates-on-the-case-page/specs/realtime-updates-ui/spec.md
 */
export function installCaseLiveUpdates(router, storeFactory) {
	let subscription = null

	router.afterEach((to) => {
		// The store is resolved on the first navigation and not at install
		// time: pinia is installed on the app after the router is built, and
		// asking for a store before that throws.
		if (subscription === null) {
			subscription = new CaseLiveSubscription(storeFactory())
		}
		subscription.sync(caseIdOfRoute(to))
	})

	return {
		/**
		 * @return {CaseLiveSubscription|null} The subscription, once one exists.
		 */
		get subscription() {
			return subscription
		},
	}
}
