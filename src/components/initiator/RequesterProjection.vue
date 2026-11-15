<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<!-- Headless on purpose: nothing to draw. The card this came out of is
	     gone from the page; only its back-fill stays. -->
	<span
		class="requester-projection"
		hidden
		aria-hidden="true"
		data-testid="requester-projection" />
</template>

<script>
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { companyResult, personResult } from '../../services/initiatorSearch.js'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'

/**
 * Derive a case's requester projection from its canonical `requester` uuid.
 *
 * A FALLBACK. The server writes the projection in the save that sets the
 * requester (CaseRequesterProjectionListener), so a case saved since then
 * already carries the right one and this writes nothing. It still compares and
 * rewrites, for cases saved before the listener existed.
 *
 * Two writers reach `case.requester`: the picker, which writes the projection
 * (`initiatorType`, `initiatorSourceId`, `initiatorDisplayName`) alongside
 * it, and the ns#Case semantic handoff, which writes the reference alone.
 * The case list's Requester column and its filter read the projection, so
 * without this a handed-off case lists as having no requester at all. The
 * initiator card used to do this as a side effect of rendering; the card
 * left the page on 2026-09-12 and the back-fill stays, mounted through the
 * page's actions slot so it runs on every case page load.
 *
 * @spec openspec/specs/initiator-display/spec.md
 */
const PAGE_REFRESH = 'cn:page:refresh'

// The register sets a requester uuid can come from, asked in this order.
const REQUESTER_SOURCES = [
	['brpPerson', personResult, 'person'],
	['kvkCompany', companyResult, 'company'],
]

/**
 * The projection fields of one shaped requester row.
 *
 * @param {object} result The row as an initiator result.
 * @param {string} type The initiator type.
 * @return {object} The projection.
 */
function projectionOf(result, type) {
	return {
		initiatorType: type,
		initiatorSourceId: String(result.sourceId || ''),
		initiatorDisplayName: result.displayName || '',
	}
}

/**
 * Whether the projection's number is not the number: shown masked
 * ("*****7609") or not at all.
 *
 * @param {object} projection The projection.
 * @return {boolean} True for an unknown number.
 */
function hasUnknownNumber(projection) {
	return projection.initiatorSourceId === '' || projection.initiatorSourceId.includes('*')
}

/**
 * Whether the case already names the projection's requester.
 *
 * @param {object} caseObject The case.
 * @param {object} projection The projection.
 * @return {boolean} True for the same type and name.
 */
function isSameRequester(caseObject, projection) {
	return String(caseObject.initiatorType ?? '') === projection.initiatorType
		&& String(caseObject.initiatorDisplayName ?? '') === projection.initiatorDisplayName
}

/**
 * Leave out an unknown number: the case keeps the one it holds for the same
 * requester rather than storing the mask over it, and a new requester gets
 * an empty one.
 *
 * @param {object} projection The projection, changed in place.
 * @param {object} caseObject The case.
 * @return {object} The projection.
 */
function keepKnownNumber(projection, caseObject) {
	const unknownNumber = hasUnknownNumber(projection)
	if (unknownNumber && isSameRequester(caseObject, projection)) {
		delete projection.initiatorSourceId
	} else if (unknownNumber) {
		projection.initiatorSourceId = ''
	}
	return projection
}

/**
 * Whether the case already carries every projection field.
 *
 * @param {object} caseObject The case.
 * @param {object} projection The projection.
 * @return {boolean} True when nothing would change.
 */
function projectionUnchanged(caseObject, projection) {
	return Object.keys(projection).every((key) => String(caseObject[key] ?? '') === projection[key])
}

export default {
	name: 'RequesterProjection',

	computed: {
		/**
		 * The object store the case and the register rows are read from.
		 *
		 * @return {object} The store.
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		objectStore() {
			return useObjectStore()
		},
	},

	watch: {
		'$route.params.id': function () {
			this.run()
		},
	},

	created() {
		// A case edited in place (the case-core form) is saved without a route
		// change; the page asks its widgets to read again, and so does this.
		subscribe(PAGE_REFRESH, this.run)
	},

	beforeUnmount() {
		unsubscribe(PAGE_REFRESH, this.run)
	},

	async mounted() {
		// The manifest mounts this before App.vue's initializeStores() has
		// resolved the app-config, so the 'case' object type may not be
		// registered yet; awaiting it here is idempotent.
		await initializeStores()
		await this.run()
	},

	methods: {
		/**
		 * Read the case on the route and write its projection when it has a
		 * requester and no projection yet.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async run() {
			const caseObject = await this.loadRouteCase()
			if (!caseObject?.requester) {
				return
			}
			const projection = await this.requesterProjection(caseObject)
			// Written only when it differs: a requester replaced in a form
			// still carries the old one's name and type until this runs.
			if (projection === null || projectionUnchanged(caseObject, projection)) {
				return
			}
			await this.writeProjection(caseObject, projection)
		},

		/**
		 * The case on the route, or null when there is none or it did not load.
		 *
		 * @return {Promise<object|null>} The case.
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async loadRouteCase() {
			const caseId = this.$route?.params?.id
			if (!caseId) {
				return null
			}
			try {
				return (await this.objectStore.fetchObject('case', caseId)) || {}
			} catch {
				// Nothing to project from a case that did not load; the page's
				// own widgets report that failure where a reader looks.
				return null
			}
		},

		/**
		 * The projection of the case's requester from the first register set
		 * that holds it, or null when neither does.
		 *
		 * @param {object} caseObject The case.
		 * @return {Promise<object|null>} The projection.
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async requesterProjection(caseObject) {
			// A uuid says nothing about which register set it came from, so
			// both are asked in turn.
			for (const [schema, shape, type] of REQUESTER_SOURCES) {
				const row = await this.fetchRow(schema, caseObject.requester)
				if (row) {
					return keepKnownNumber(projectionOf(shape(row), type), caseObject)
				}
			}
			return null
		},

		/**
		 * Save the case with its projection.
		 *
		 * @param {object} caseObject The case.
		 * @param {object} projection The projection.
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async writeProjection(caseObject, projection) {
			try {
				await this.objectStore.saveObject('case', {
					...caseObject,
					...projection,
					// saveObject PUTs only when the payload names an id; an
					// OpenRegister object carries it in @self as well as at
					// the top level, and without it the back-fill would
					// CREATE a second case.
					id: caseObject.id || caseObject['@self']?.id,
				})
			} catch {
				// Written again on the next load: a refused write leaves
				// the case exactly as it was, and there is no reader here
				// to tell.
			}
		},

		/**
		 * One row by uuid, or null when this register set does not hold it.
		 *
		 * @param {string} schema The schema slug.
		 * @param {string} uuid The row's uuid.
		 * @return {Promise<object|null>} The row.
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async fetchRow(schema, uuid) {
			try {
				return await this.objectStore.fetchObject(schema, uuid)
			} catch {
				return null
			}
		},
	},
}
</script>
