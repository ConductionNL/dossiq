<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="leges-admin">
		<div class="leges-admin__header">
			<h2>{{ t('procest', 'Legesverordeningen') }}</h2>
			<NcButton type="primary" @click="showImport = true">
				{{ t('procest', 'Verordening importeren') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<CnEmptyState
			v-else-if="verordeningen.length === 0"
			:name="t('procest', 'Geen verordeningen')"
			:description="t('procest', 'Importeer een legesverordening uit een raadsbesluit om te beginnen.')" />

		<table v-else class="leges-admin__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Naam') }}</th>
					<th>{{ t('procest', 'Geldig vanaf') }}</th>
					<th>{{ t('procest', 'Status') }}</th>
					<th>{{ t('procest', 'Acties') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="v in verordeningen" :key="v.id || v.uuid">
					<td>{{ v.naam }}</td>
					<td>{{ v.geldigVanaf }}</td>
					<td>
						<CnStatusBadge :status="statusLabel(v.status)" :type="statusType(v.status)" />
					</td>
					<td>
						<NcButton
							v-if="v.status === 'concept'"
							type="primary"
							:disabled="approving === (v.id || v.uuid)"
							@click="approve(v)">
							{{ t('procest', 'Vaststellen') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<LegesVerordeningImportDialog
			:open="showImport"
			@close="showImport = false"
			@imported="onImported" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { CnEmptyState, CnStatusBadge } from '@conduction/nextcloud-vue'
import { approveVerordening, listVerordeningen } from '../../services/legesApi.js'
import LegesVerordeningImportDialog from '../../dialogs/LegesVerordeningImportDialog.vue'

const STATUS_LABELS = {
	concept: 'Concept',
	vastgesteld: 'Vastgesteld',
	vervallen: 'Vervallen',
}

export default {
	name: 'LegesVerordeningenAdmin',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		CnEmptyState,
		CnStatusBadge,
		LegesVerordeningImportDialog,
	},
	data() {
		return {
			verordeningen: [],
			loading: false,
			error: '',
			showImport: false,
			approving: null,
		}
	},
	mounted() {
		this.fetch()
	},
	methods: {
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-009 */
		async fetch() {
			this.loading = true
			this.error = ''
			try {
				this.verordeningen = await listVerordeningen()
			} catch (err) {
				this.error = this.t('procest', 'Kon verordeningen niet laden')
				console.error('Procest leges list failed', err)
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param v
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-009
		 */
		async approve(v) {
			const id = v.id || v.uuid
			this.approving = id
			this.error = ''
			try {
				await approveVerordening(id)
				await this.fetch()
			} catch (err) {
				this.error = err?.response?.data?.error || this.t('procest', 'Vaststellen mislukt')
				console.error('Procest leges approve failed', err)
			} finally {
				this.approving = null
			}
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-001 */
		onImported() {
			this.showImport = false
			this.fetch()
		},
		/**
		 * @param status
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-009
		 */
		statusLabel(status) {
			return this.t('procest', STATUS_LABELS[status] || status)
		},
		/**
		 * @param status
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-009
		 */
		statusType(status) {
			if (status === 'vastgesteld') return 'success'
			if (status === 'vervallen') return 'warning'
			return 'info'
		},
	},
}
</script>

<style scoped>
.leges-admin {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.leges-admin__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.leges-admin__table {
	width: 100%;
	border-collapse: collapse;
}

.leges-admin__table th,
.leges-admin__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}
</style>
