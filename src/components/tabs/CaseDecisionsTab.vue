<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: decisions (besluiten) linked to a parent case.

 Lists decision records where decision.case === the parent case id,
 with create/edit/delete via CnFormDialog (decisionType offered as a
 select over the register's decisionType records). Receives `objectId`
 from CnObjectSidebar's sharedTabProps, with a route fallback.
-->
<template>
	<div class="case-tab case-tab--decisions">
		<div class="case-tab__header">
			<h3 class="case-tab__title">
				{{ t('procest', 'Decisions') }}
				<span v-if="decisions.length > 0" class="case-tab__count">({{ decisions.length }})</span>
			</h3>
			<NcButton type="primary" @click="openCreate">
				{{ t('procest', 'Add decision') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="decisions.length === 0"
			:title="t('procest', 'No decisions yet')"
			:description="t('procest', 'Record a decision (besluit) taken on this case.')" />

		<ul v-else class="case-tab__list">
			<li
				v-for="decision in decisions"
				:key="decision.id"
				class="case-tab__item"
				@click="openEdit(decision)">
				<div class="case-tab__row">
					<strong class="case-tab__item-title">{{ decision.title || '—' }}</strong>
					<NcActions :inline="0" @click.native.stop>
						<NcActionButton @click="openEdit(decision)">
							{{ t('procest', 'Edit') }}
						</NcActionButton>
						<NcActionButton @click="openDelete(decision)">
							{{ t('procest', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
				<div class="case-tab__meta">
					<span v-if="decisionTypeName(decision.decisionType)">
						{{ decisionTypeName(decision.decisionType) }}
					</span>
					<span v-if="decision.decisionDate">
						{{ t('procest', 'Decided: {date}', { date: formatDate(decision.decisionDate) }) }}
					</span>
					<span v-if="decision.effectiveDate">
						{{ t('procest', 'Effective: {date}', { date: formatDate(decision.effectiveDate) }) }}
					</span>
				</div>
			</li>
		</ul>

		<CnFormDialog
			v-if="showFormDialog"
			ref="formDialog"
			:fields="formFields"
			:item="editItem"
			:dialog-title="editItem ? t('procest', 'Edit decision') : t('procest', 'Add decision')"
			@confirm="onFormConfirm"
			@close="showFormDialog = false" />

		<CnDeleteDialog
			v-if="deleteItem"
			ref="deleteDialog"
			:item="deleteItem"
			@confirm="onDeleteConfirm"
			@close="deleteItem = null" />
	</div>
</template>

<script>
import { NcActionButton, NcActions, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { CnDeleteDialog, CnFormDialog } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { formatDate } from '../../utils/caseHelpers.js'

export default {
	name: 'CaseDecisionsTab',
	components: {
		NcActionButton,
		NcActions,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CnDeleteDialog,
		CnFormDialog,
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
			decisions: [],
			decisionTypes: [],
			loading: true,
			showFormDialog: false,
			editItem: null,
			deleteItem: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		resolvedCaseId() {
			return this.objectId || this.$route?.params?.id || null
		},
		formFields() {
			const enumLabels = {}
			for (const dt of this.decisionTypes) {
				enumLabels[dt.id] = dt.title || dt.name || dt.id
			}
			return [
				{ key: 'title', label: t('procest', 'Title'), required: true },
				{
					key: 'decisionType',
					label: t('procest', 'Decision type'),
					widget: 'select',
					enum: this.decisionTypes.map(dt => dt.id),
					enumLabels,
				},
				{ key: 'decisionDate', label: t('procest', 'Decision date'), widget: 'datetime' },
				{ key: 'effectiveDate', label: t('procest', 'Effective date'), widget: 'datetime' },
				{ key: 'description', label: t('procest', 'Description'), widget: 'textarea' },
			]
		},
	},
	watch: {
		resolvedCaseId() {
			this.reload()
		},
	},
	async mounted() {
		await initializeStores()
		await this.reload()
	},
	methods: {
		formatDate,
		async reload() {
			if (!this.resolvedCaseId) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				const [decisions, decisionTypes] = await Promise.all([
					this.objectStore.fetchCollection('decision', {
						'_filters[case]': this.resolvedCaseId,
						_limit: 100,
					}),
					this.objectStore.fetchCollection('decisionType', { _limit: 100 }).catch(() => []),
				])
				this.decisions = decisions || []
				this.decisionTypes = decisionTypes || []
			} catch (err) {
				console.error('[CaseDecisionsTab] failed to fetch decisions', err)
				this.decisions = []
			} finally {
				this.loading = false
			}
		},
		decisionTypeName(id) {
			if (!id) return null
			const dt = this.decisionTypes.find(d => d.id === id)
			return dt ? (dt.title || dt.name || id) : id
		},
		openCreate() {
			this.editItem = null
			this.showFormDialog = true
		},
		openEdit(decision) {
			this.editItem = decision
			this.showFormDialog = true
		},
		openDelete(decision) {
			this.deleteItem = decision
		},
		async onFormConfirm(formData) {
			try {
				const result = await this.objectStore.saveObject('decision', {
					...formData,
					case: this.resolvedCaseId,
				})
				if (result) {
					this.$refs.formDialog.setResult({ success: true })
					await this.reload()
				} else {
					this.$refs.formDialog.setResult({ error: t('procest', 'Could not save decision') })
				}
			} catch (err) {
				this.$refs.formDialog.setResult({ error: err.message || t('procest', 'Could not save decision') })
			}
		},
		async onDeleteConfirm(id) {
			try {
				await this.objectStore.deleteObject('decision', id)
				this.$refs.deleteDialog.setResult({ success: true })
				await this.reload()
			} catch (err) {
				this.$refs.deleteDialog.setResult({ error: err.message || t('procest', 'Could not delete decision') })
			}
		},
	},
}
</script>

<style scoped>
.case-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.case-tab__title {
	margin: 0;
	font-size: 16px;
}

.case-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.case-tab__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.case-tab__item {
	padding: 8px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.case-tab__item:hover {
	background: var(--color-background-hover);
}

.case-tab__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.case-tab__item-title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.case-tab__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
