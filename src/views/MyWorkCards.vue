<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnIndexPage
		:title="t('procest', 'My Work')"
		register="procest"
		schema="case"
		:filter="filter"
		view-mode="cards"
		:view-modes="['cards', 'table']"
		:columns="columns"
		:show-view-action="false"
		@view="openCase"
		@row-click="openCase">
		<!-- Custom card so case-type + status render as names, not raw UUIDs
		     (card view does not apply column formatters). -->
		<template #card="{ object, selected }">
			<MyWorkCaseCard
				:object="object"
				:selected="selected"
				:case-type-map="caseTypeMap"
				:status-map="statusMap"
				@open="openCase" />
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import MyWorkCaseCard from './MyWorkCaseCard.vue'
import { initializeStores } from '../store/store.js'
import { useObjectStore } from '../store/modules/object.js'

/**
 * My Work — the current user's assigned cases, rendered as a standard
 * CnIndexPage card list. A thin wrapper (rather than a bare manifest
 * `type: index` page) because the stock index base-filter resolves only
 * `@route.*` tokens, not the `@me` current-user token; here we inject the
 * resolved uid into the `assignee` filter so the same self-fetch index path
 * scopes to the signed-in user.
 */
export default {
	name: 'MyWorkCards',

	components: { CnIndexPage, MyWorkCaseCard },

	data() {
		return {
			/** { caseTypeUuid: humanName } for the card's Case type chip. */
			caseTypeMap: {},
			/** { statusTypeUuid: humanName } for the card's Status chip. */
			statusMap: {},
		}
	},

	computed: {
		/**
		 * Base filter scoping the case list to the current user's assignments.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 */
		filter() {
			const uid = (getCurrentUser() && getCurrentUser().uid)
				|| (typeof OC !== 'undefined' && OC.currentUser)
				|| ''
			return { assignee: uid }
		},

		/**
		 * Curated card/table columns, mirroring the Cases index.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 */
		columns() {
			return [
				'identifier',
				'title',
				{ key: 'caseType', label: this.t('procest', 'Case type'), formatter: 'caseTypeName' },
				{ key: 'status', label: this.t('procest', 'Status'), formatter: 'statusTypeName' },
				'deadline',
			]
		},
	},

	/**
	 * Load the caseType / statusType collections up front and build UUID→name
	 * maps so the cards show human names (card view does not apply the column
	 * formatters, and the lazy formatter self-load is unreliable through a
	 * scoped-slot child's computed).
	 */
	async mounted() {
		await initializeStores()
		const store = useObjectStore()
		try {
			const [caseTypes, statuses] = await Promise.all([
				store.fetchCollection('caseType', { _limit: 200 }),
				store.fetchCollection('statusType', { _limit: 200 }),
			])
			this.caseTypeMap = this.buildNameMap(caseTypes)
			this.statusMap = this.buildNameMap(statuses)
		} catch (e) {
			// Names simply fall back to hidden chips; never block the list.
		}
	},

	methods: {
		/**
		 * Build a UUID→name map from an OpenRegister collection.
		 *
		 * @param {Array<object>} collection The fetched objects.
		 * @return {Object<string, string>} id → title/name.
		 */
		buildNameMap(collection) {
			const map = {}
			for (const o of (collection || [])) {
				const id = o.id || (o['@self'] && o['@self'].id)
				if (id) {
					map[id] = o.title || o.name || String(id)
				}
			}
			return map
		},

		/**
		 * Open a case detail page from a clicked row/card.
		 *
		 * @param {object} row The case object emitted by CnIndexPage.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 */
		openCase(row) {
			const id = (row && (row.id || row.uuid))
				|| (row && row['@self'] && row['@self'].id)
			if (id) {
				this.$router.push({ name: 'CaseDetail', params: { id: String(id) } })
			}
		},
	},
}
</script>
