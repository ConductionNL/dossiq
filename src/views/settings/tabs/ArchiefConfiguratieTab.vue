<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Archief — Bewaartermijnregels (retention rules) admin tab.
-->
<template>
	<div class="archief-config-tab">
		<div class="archief-config-tab__header">
			<h3>{{ t('procest', 'Archief retention rules') }}</h3>
			<p class="archief-config-tab__description">
				{{ t('procest', 'Configure retention periods per zaaktype. Cases reaching their retention threshold trigger e-Depot handover; permanent retention skips archive submission.') }}
			</p>
			<NcButton type="primary" @click="openNew">
				<template #icon><Plus :size="18" /></template>
				{{ t('procest', 'New rule') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

		<NcEmptyContent
			v-if="!loading && rules.length === 0"
			:name="t('procest', 'No retention rules')"
			:description="t('procest', 'No bewaartermijnregels configured. Add one per zaaktype to enable scheduled archive handover.')">
			<template #icon><FolderClock :size="48" /></template>
		</NcEmptyContent>

		<table v-if="!loading && rules.length > 0" class="archief-config-tab__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Zaaktype') }}</th>
					<th>{{ t('procest', 'Bewaartermijn') }}</th>
					<th>{{ t('procest', 'Vernietiging') }}</th>
					<th>{{ t('procest', 'Trigger') }}</th>
					<th>{{ t('procest', 'Acties') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="r in rules" :key="r.id">
					<td>{{ r.zaaktypeKey }}</td>
					<td>
						<template v-if="(r.bewaartermijnJaren || 0) >= 9999">
							{{ t('procest', 'Permanent') }}
						</template>
						<template v-else>
							{{ t('procest', '{n} years', { n: r.bewaartermijnJaren }) }}
						</template>
					</td>
					<td>{{ r.vernietiging ? t('procest', 'Yes') : t('procest', 'No') }}</td>
					<td>{{ r.triggerGebeurtenis || t('procest', 'sluitingsdatum') }}</td>
					<td>
						<NcButton size="small" @click="openEdit(r)">{{ t('procest', 'Edit') }}</NcButton>
						<NcButton size="small" type="error" @click="confirmDelete(r)">{{ t('procest', 'Delete') }}</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<ArchiefRuleEditor
			v-if="editorOpen"
			:rule="editingRule"
			@save="onSave"
			@close="closeEditor" />

		<NcDialog
			v-if="deleting"
			:open="!!deleting"
			:name="t('procest', 'Delete retention rule')"
			@update:open="v => { if (!v) deleting = null }">
			<p>{{ t('procest', 'Delete the retention rule for {z}? Cases already in the e-Depot handover pipeline are not affected.', { z: deleting.zaaktypeKey }) }}</p>
			<template #actions>
				<NcButton @click="deleting = null">{{ t('procest', 'Cancel') }}</NcButton>
				<NcButton type="error" @click="doDelete">{{ t('procest', 'Delete') }}</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import Plus from 'vue-material-design-icons/Plus.vue'
import FolderClock from 'vue-material-design-icons/FolderClock.vue'
import ArchiefRuleEditor from '../../../modals/ArchiefRuleEditor.vue'

export default {
	name: 'ArchiefConfiguratieTab',
	components: { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard, Plus, FolderClock, ArchiefRuleEditor },
	data() {
		return {
			loading: false,
			error: null,
			rules: [],
			editorOpen: false,
			editingRule: null,
			deleting: null,
		}
	},
	mounted() {
		this.load()
	},
	methods: {
		t,
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		async load() {
			this.loading = true
			this.error = null
			try {
				const res = await axios.get(generateUrl('/apps/procest/api/archief/rules'))
				this.rules = Array.isArray(res.data) ? res.data : (res.data?.results || [])
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to load rules')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		openNew() {
			this.editingRule = null
			this.editorOpen = true
		},
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		openEdit(r) {
			this.editingRule = r
			this.editorOpen = true
		},
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		closeEditor() {
			this.editorOpen = false
			this.editingRule = null
		},
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		confirmDelete(r) {
			this.deleting = r
		},
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		async onSave(payload) {
			try {
				if (this.editingRule && this.editingRule.id) {
					await axios.put(
						generateUrl('/apps/procest/api/archief/rules/' + encodeURIComponent(this.editingRule.id)),
						payload,
					)
				} else {
					await axios.post(generateUrl('/apps/procest/api/archief/rules'), payload)
				}
				this.closeEditor()
				this.load()
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to save')
			}
		},
		/** @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md */
		async doDelete() {
			if (!this.deleting) return
			try {
				await axios.delete(generateUrl('/apps/procest/api/archief/rules/' + encodeURIComponent(this.deleting.id)))
			} catch (e) { /* swallow */ }
			this.deleting = null
			this.load()
		},
	},
}
</script>

<style scoped>
.archief-config-tab {
	padding: 8px 0;
}

.archief-config-tab__description {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px 0;
}

.archief-config-tab__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 12px;
}

.archief-config-tab__table th,
.archief-config-tab__table td {
	padding: 8px 10px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.archief-config-tab__table th {
	background: var(--color-background-dark);
	font-weight: 500;
}

.archief-config-tab__table td .nc-button {
	margin-right: 4px;
}
</style>
