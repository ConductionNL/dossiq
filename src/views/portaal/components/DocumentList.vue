<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Citizen-facing document list for a case in the "Mijn gemeente" portal
  - (zaakportaal-mijngemeente, REQ-POR-005). Renders only the documents the
  - backend has already ACL-filtered (downloadbaarVoor overlaps the citizen's
  - role) and surfaced on the case detail; internal documents never reach the
  - client. When the backend provides a per-document download URL it is linked
  - (the download endpoint re-checks the ACL and audit-logs server-side); when
  - absent the entry is shown read-only. Display-only: this component performs
  - no client-side authorisation of its own.
-->
<template>
	<section class="zp-documents" data-testid="portaal-document-list">
		<h3>{{ t('procest', 'Documents') }}</h3>

		<p v-if="!documents.length" class="zp-documents__empty" data-testid="portaal-document-empty">
			{{ t('procest', 'No documents are available for this case.') }}
		</p>

		<ul v-else class="zp-documents__list">
			<li v-for="doc in documents"
				:key="doc.id"
				class="zp-documents__item"
				data-testid="portaal-document-item">
				<a v-if="doc.downloadUrl"
					class="zp-documents__link"
					:href="doc.downloadUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ doc.naam || t('procest', 'Untitled document') }}
				</a>
				<span v-else class="zp-documents__name">{{ doc.naam || t('procest', 'Untitled document') }}</span>
				<span v-if="doc.soort" class="zp-documents__meta">{{ doc.soort }}</span>
				<span v-if="doc.datum" class="zp-documents__meta">{{ doc.datum }}</span>
			</li>
		</ul>
	</section>
</template>

<script>
import { normaliseDocuments } from '../../../utils/portaalForms.js'

export default {
	name: 'DocumentList',
	props: {
		/** Raw documents array from the case detail response (already ACL-filtered server-side). */
		rawDocuments: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		/**
		 * Normalised, render-safe documents (carries an optional downloadUrl
		 * when the backend provided one).
		 *
		 * @return {Array} The documents.
		 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
		 */
		documents() {
			return normaliseDocuments(this.rawDocuments).map((doc, i) => ({
				...doc,
				downloadUrl: (this.rawDocuments[i] && this.rawDocuments[i].downloadUrl) || '',
			}))
		},
	},
}
</script>

<style scoped>
.zp-documents {
	margin-top: 24px;
}

.zp-documents__empty {
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.zp-documents__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.zp-documents__item {
	display: flex;
	align-items: baseline;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border, #d0d0d0);
}

.zp-documents__link {
	color: var(--color-primary-element, #21468B);
	text-decoration: underline;
}

.zp-documents__link:focus {
	outline: 2px solid var(--color-primary-element, #21468B);
}

.zp-documents__name {
	color: var(--color-main-text, #222);
}

.zp-documents__meta {
	color: var(--color-text-maxcontrast, #6b6b6b);
	font-size: 0.9em;
}
</style>
