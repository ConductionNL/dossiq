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
		@row-click="openCase" />
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'

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

	components: { CnIndexPage },

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

	methods: {
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
