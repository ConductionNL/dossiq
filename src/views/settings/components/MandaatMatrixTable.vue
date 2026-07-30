<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="mandaat-matrix-table">
		<div class="mandaat-matrix-table__toolbar">
			<NcButton type="primary" @click="$emit('edit', null)">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('procest', 'New mandaat') }}
			</NcButton>
			<NcButton type="secondary" @click="$emit('import')">
				<template #icon>
					<Import :size="18" />
				</template>
				{{ t('procest', 'Import from Decidesk') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-if="!loading && matrices.length === 0"
			:name="t('procest', 'No mandate decisions')"
			:description="t('procest', 'No MandateringsBesluit entries yet. Create one or import an export.')">
			<template #icon>
				<FileDocumentMultiple :size="48" />
			</template>
		</NcEmptyContent>

		<table v-if="!loading && matrices.length > 0" class="mandaat-matrix-table__table">
			<thead>
				<tr>
					<th>{{ t('procest', '#') }}</th>
					<th>{{ t('procest', 'Naam') }}</th>
					<th>{{ t('procest', 'Status') }}</th>
					<th>{{ t('procest', 'In werkingtreding') }}</th>
					<th>{{ t('procest', 'Expiry date') }}</th>
					<th>{{ t('procest', 'Acties') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(b, idx) in matrices" :key="b.id">
					<td>{{ idx + 1 }}</td>
					<td>{{ b.naam || b.mandaatNummer || b.id }}</td>
					<td>
						<span class="mandaat-matrix-table__badge" :class="badgeClass(b.status)">{{ b.status || '—' }}</span>
					</td>
					<td>{{ b.inWerkingtreding || '—' }}</td>
					<td>{{ b.vervaldatum || '—' }}</td>
					<td>
						<NcButton size="small" @click="$emit('edit', b)">
							{{ t('procest', 'Edit') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import Plus from 'vue-material-design-icons/Plus.vue'
import Import from 'vue-material-design-icons/Import.vue'
import FileDocumentMultiple from 'vue-material-design-icons/FileDocumentMultiple.vue'

export default {
	name: 'MandaatMatrixTable',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, Plus, Import, FileDocumentMultiple },
	props: {
		matrices: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
	},
	emits: ['edit', 'import'],
	methods: {
		t,
		/**
		 * @param status
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		badgeClass(status) {
			const s = (status || '').toLowerCase()
			if (s === 'actief' || s === 'active') return 'mandaat-matrix-table__badge--ok'
			if (s === 'vervallen' || s === 'expired') return 'mandaat-matrix-table__badge--alert'
			if (s === 'concept' || s === 'draft') return 'mandaat-matrix-table__badge--neutral'
			return 'mandaat-matrix-table__badge--neutral'
		},
	},
}
</script>

<style scoped>
.mandaat-matrix-table__toolbar {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
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

.mandaat-matrix-table__badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 8px;
	display: inline-block;
}

.mandaat-matrix-table__badge--ok {
	background: var(--color-success);
	color: var(--color-main-background);
}

.mandaat-matrix-table__badge--alert {
	background: var(--color-error);
	color: var(--color-main-background);
}

.mandaat-matrix-table__badge--neutral {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
