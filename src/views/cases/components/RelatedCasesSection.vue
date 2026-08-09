<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  "Gerelateerde zaken" section — case-detail sidebar tab listing the typed
  peer relations (relevanteAndereZaken) of a case. Each entry shows a
  direction-aware relation-type label, the related case title + status, the
  toelichting, and a link navigating to the case. Targets the viewer cannot
  read under OpenRegister RBAC render as a masked stub (case number + type
  only, no title, no navigation) — the relation's existence is not hidden,
  but no content leaks. Add/remove route through CaseRelationService.

  @spec openspec/specs/related-case-linking/spec.md
-->
<template>
	<div class="related-cases-section">
		<div class="related-cases-section__header">
			<h3>{{ t('procest', 'Related cases') }}</h3>
			<NcButton type="secondary" @click="showAddModal = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('procest', 'Link case') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="relations.length === 0"
			:name="t('procest', 'No related cases')"
			:description="t('procest', 'Link this case to a follow-up, subject, or contributing case.')" />

		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th scope="col">{{ t('procest', 'Relation') }}</th>
						<th scope="col">{{ t('procest', 'Case') }}</th>
						<th scope="col">{{ t('procest', 'Status') }}</th>
						<th scope="col">{{ t('procest', 'Explanation') }}</th>
						<th class="related-cases-section__actions-col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="rel in relations" :key="rel.caseId + ':' + rel.aardRelatie" class="viewTableRow">
						<td>{{ typeLabel(rel.aardRelatie) }}</td>
						<td>
							<a
								v-if="rel.readable"
								href="#"
								@click.prevent="openCase(rel.caseId)">
								{{ rel.title }}
							</a>
							<span v-else class="related-cases-section__masked" :title="t('procest', 'You do not have access to this case')">
								{{ rel.maskedNumber }}
							</span>
						</td>
						<td>{{ rel.readable ? (rel.status || '—') : '—' }}</td>
						<td>{{ rel.toelichting || '—' }}</td>
						<td class="related-cases-section__actions-col">
							<NcButton
								type="tertiary"
								:aria-label="t('procest', 'Remove relation')"
								@click="remove(rel)">
								<template #icon>
									<Delete :size="18" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<AddCaseRelationModal
			v-if="showAddModal"
			:case-id="resolvedCaseId"
			@created="onCreated"
			@close="showAddModal = false" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { useObjectStore } from '../../../store/modules/object.js'
import { fetchRelations, removeRelation, relationTypeLabel } from '../../../services/caseRelationApi.js'
import AddCaseRelationModal from '../../../modals/AddCaseRelationModal.vue'

export default {
	name: 'RelatedCasesSection',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Plus,
		Delete,
		AddCaseRelationModal,
	},
	props: {
		/** Case UUID — passed by CnObjectSidebar as a shared tab prop. */
		objectId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			loading: true,
			relations: [],
			showAddModal: false,
		}
	},
	computed: {
		/** @spec openspec/specs/related-case-linking/spec.md */
		objectStore() {
			return useObjectStore()
		},
		/** @spec openspec/specs/related-case-linking/spec.md */
		resolvedCaseId() {
			return this.objectId || this.$route?.params?.id || null
		},
	},
	watch: {
		resolvedCaseId: {
			immediate: true,
			/** @spec openspec/specs/related-case-linking/spec.md */
			handler() {
				this.load()
			},
		},
	},
	methods: {
		/**
		 * Localised, direction-aware relation-type label.
		 *
		 * @param {string} aardRelatie Relation type.
		 * @return {string} Label.
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		typeLabel(aardRelatie) {
			return relationTypeLabel(aardRelatie)
		},
		/**
		 * Load the relation list and hydrate each target case for display,
		 * masking targets the viewer cannot read.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		async load() {
			if (!this.resolvedCaseId) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				const raw = await fetchRelations(this.resolvedCaseId)
				const hydrated = []
				for (const entry of raw) {
					hydrated.push(await this.hydrate(entry))
				}
				this.relations = hydrated
			} catch (e) {
				this.relations = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Resolve a relation entry's target case for display. An unreadable
		 * target (object store rejects/returns nothing) becomes a masked stub.
		 *
		 * @param {object} entry Relation entry {caseId, aardRelatie, toelichting?}.
		 * @return {Promise<object>} Display row.
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		async hydrate(entry) {
			const row = {
				caseId: entry.caseId,
				aardRelatie: entry.aardRelatie,
				toelichting: entry.toelichting || '',
				readable: false,
				title: '',
				status: '',
				maskedNumber: entry.caseId,
			}
			try {
				const obj = await this.objectStore.fetchObject('case', entry.caseId)
				const data = obj?.data || obj
				if (data && (data.id || data['@self']?.id)) {
					row.readable = true
					row.title = data.title || data.identifier || entry.caseId
					row.status = data.status || ''
					row.maskedNumber = data.identifier || entry.caseId
				}
			} catch (e) {
				row.readable = false
			}
			return row
		},
		/**
		 * Navigate to a related case detail.
		 *
		 * @param {string} caseId Target case UUID.
		 * @return {void}
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		openCase(caseId) {
			this.$router.push({ path: `/cases/${caseId}` })
		},
		/**
		 * Remove a relation (two-sided) and refresh.
		 *
		 * @param {object} rel The relation row.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		async remove(rel) {
			await removeRelation(this.resolvedCaseId, rel.caseId, rel.aardRelatie)
			await this.load()
		},
		/**
		 * Refresh after a relation was created.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/related-case-linking/spec.md
		 */
		async onCreated() {
			await this.load()
		},
	},
}
</script>

<style scoped>
.related-cases-section__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.related-cases-section__masked {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.related-cases-section__actions-col {
	width: 48px;
	text-align: right;
}
</style>
