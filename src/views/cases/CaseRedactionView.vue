<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The redaction page of a case (`/cases/:id/redact`), board DqLakken: the
	library's CnRedactionEditor with the case's breadcrumb. The page's manifest
	config carries the routes, the grounds path and the passage type labels.
	@spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
-->
<template>
	<CnRedactionEditor
		class="case-redaction"
		data-testid="document-redaction-page"
		:recordId="id"
		:config="{ urls, typeLabels, groundsPath }">
		<template #crumbs>
			<nav class="case-redaction__crumbs" :aria-label="t('dossiq', 'Breadcrumb')" data-testid="document-redaction-crumbs">
				<router-link :to="{ name: 'Cases' }">
					{{ t('dossiq', 'All cases') }}
				</router-link>
				<span aria-hidden="true">/</span>
				<router-link :to="{ name: 'CaseDetail', params: { id } }">
					{{ (kase && kase.identifier) || t('dossiq', 'Case') }}
				</router-link>
				<span aria-hidden="true">/</span>
				<span>{{ t('dossiq', 'Redact') }}</span>
			</nav>
		</template>
	</CnRedactionEditor>
</template>

<script>
import { CnRedactionEditor } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { fetchCaseSummary } from '../../services/caseSummary.js'

export default {
	name: 'CaseRedactionView',

	components: { CnRedactionEditor },

	props: {
		/** The case, from the route. */
		id: {
			type: String,
			default: '',
		},

		/** The redaction routes: `{ list, detect, check, redact, upload }`, with `{recordId}` and `{documentRef}`. */
		urls: {
			type: Object,
			default: () => ({}),
		},

		/** What a passage type is called: `{ PERSON: 'Name', ... }`. */
		typeLabels: {
			type: Object,
			default: () => ({}),
		},

		/** Where a document lists its refusal grounds. */
		groundsPath: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			kase: null,
		}
	},

	/**
	 * Read the case for its number in the breadcrumb.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
	 */
	async mounted() {
		this.kase = await fetchCaseSummary(this.id)
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.case-redaction {
	padding: 20px 28px 28px;
}

.case-redaction__crumbs {
	display: flex;
	gap: 8px;
	margin-top: 6px;
	color: var(--color-text-maxcontrast);
}
</style>
