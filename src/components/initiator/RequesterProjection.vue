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
import { companyResult, personResult } from '../../services/initiatorSearch.js'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'

/**
 * Fill a case's requester projection from its canonical `requester` uuid.
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
			const caseId = this.$route?.params?.id
			if (!caseId) {
				return
			}
			let caseObject
			try {
				caseObject =
					(await this.objectStore.fetchObject('case', caseId)) || {}
			} catch {
				// Nothing to project from a case that did not load; the page's
				// own widgets report that failure where a reader looks.
				return
			}
			const requester = caseObject.requester
			if (!requester || caseObject.initiatorDisplayName) {
				return
			}
			// A uuid says nothing about which register set it came from, so
			// both are asked in turn.
			for (const [schema, shape, type] of [
				['brpPerson', personResult, 'person'],
				['kvkCompany', companyResult, 'company'],
			]) {
				const row = await this.fetchRow(schema, requester)
				if (!row) {
					continue
				}
				const result = shape(row)
				const projection = {
					initiatorType: type,
					initiatorSourceId: String(result.sourceId || ''),
					initiatorDisplayName: result.displayName || '',
				}
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
				return
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
