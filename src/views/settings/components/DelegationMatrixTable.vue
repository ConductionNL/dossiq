<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="mandaat-matrix-table">
		<div class="mandaat-matrix-table__toolbar">
			<NcButton variant="secondary" @click="$emit('edit', null)">
				{{ t('dossiq', 'New mandaat') }}
			</NcButton>
			<NcButton variant="secondary" @click="$emit('import')">
				{{ t('dossiq', 'Import from Decidiq') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-if="!loading && matrices.length === 0"
			:name="t('dossiq', 'No mandate decisions')"
			:description="
				t(
					'dossiq',
					'No MandateringsBesluit entries yet. Create one or import an export.',
				)
			">
			<template #icon>
				<FileDocumentMultiple :size="48" />
			</template>
		</NcEmptyContent>

		<table
			v-if="!loading && matrices.length > 0"
			class="mandaat-matrix-table__table">
			<thead>
				<tr>
					<th scope="col">{{ t('dossiq', '#') }}</th>
					<th scope="col">{{ t('dossiq', 'Naam') }}</th>
					<th scope="col">{{ t('dossiq', 'Status') }}</th>
					<th scope="col">{{ t('dossiq', 'In werkingtreding') }}</th>
					<th scope="col">{{ t('dossiq', 'Expiry date') }}</th>
					<th scope="col">{{ t('dossiq', 'Acties') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(b, idx) in matrices" :key="b.id">
					<td>{{ idx + 1 }}</td>
					<td>{{ b.name || b.decisionName || b.mandateNumber || b.id }}</td>
					<td>
						<span
							class="cn-status-badge"
							:class="badgeClass(b.status)"
							>{{ statusLabel(b.status) }}</span
						>
					</td>
					<td>{{ b.inWerkingtreding || '—' }}</td>
					<td>{{ b.vervaldatum || '—' }}</td>
					<td>
						<NcButton size="small" @click="$emit('edit', b)">
							{{ t('dossiq', 'Edit') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import FileDocumentMultiple from 'vue-material-design-icons/FileDocumentMultiple.vue'

export default {
	name: 'DelegationMatrixTable',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		FileDocumentMultiple,
	},

	props: {
		matrices: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
	},

	emits: ['edit', 'import'],
	methods: {
		t,
		/**
		 * A mandate's status in words; an unknown status as it is stored.
		 *
		 * @param {string} status The stored status.
		 * @return {string} The label.
		 * @spec openspec/changes/deadline-definitions-board-table/specs/admin-settings/spec.md
		 */
		statusLabel(status) {
			const labels = {
				active: t('dossiq', 'Active'),
				determined: t('dossiq', 'Adopted'),
				draft: t('dossiq', 'Draft'),
				expired: t('dossiq', 'Expired'),
				lapsed: t('dossiq', 'Lapsed'),
			}
			return labels[String(status || '').toLowerCase()] || status || '—'
		},

		/**
		 * @param {string} status The status.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		badgeClass(status) {
			const s = (status || '').toLowerCase()
			if (s === 'active') {
				return 'cn-status-badge--success'
			}
			if (s === 'lapsed' || s === 'expired') {
				return 'cn-status-badge--error'
			}
			return 'cn-status-badge--default'
		},
	},
}
</script>

<style scoped>
.mandaat-matrix-table__toolbar {
	display: flex;
	gap: 8px;
	margin-bottom: 14px;
}

.mandaat-matrix-table__table {
	width: 100%;
	border-collapse: collapse;
}

.mandaat-matrix-table__table th,
.mandaat-matrix-table__table td {
	padding: 8px 10px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.mandaat-matrix-table__table th {
	background: var(--color-background-dark);
	font-weight: 500;
}
</style>
