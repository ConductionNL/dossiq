<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Toewijzingen table — MedewerkerRolToewijzing with person/role/type/from/until
  and add/end-assignment dialogs. Waarnemer rows visually distinct.
-->
<template>
	<div class="toewijzingen-table">
		<div class="toewijzingen-table__toolbar">
			<NcButton type="primary" @click="addOpen = true">
				<template #icon><Plus :size="18" /></template>
				{{ t('procest', 'Add assignment') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-if="!loading && assignments.length === 0"
			:name="t('procest', 'No role assignments')"
			:description="t('procest', 'Assign roles to employees to enable mandate-driven authorisation.')">
			<template #icon><AccountMultiple :size="48" /></template>
		</NcEmptyContent>

		<table v-if="!loading && assignments.length > 0" class="toewijzingen-table__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Person') }}</th>
					<th>{{ t('procest', 'Role') }}</th>
					<th>{{ t('procest', 'Type') }}</th>
					<th>{{ t('procest', 'Vanaf') }}</th>
					<th>{{ t('procest', 'Tot en met') }}</th>
					<th>{{ t('procest', 'Acties') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="a in assignments"
					:key="a.id"
					:class="{ 'toewijzingen-table__row--waarnemer': isWaarnemer(a) }">
					<td>{{ a.personLabel || a.medewerkerLabel || a.persoonId || a.medewerker || '—' }}</td>
					<td>{{ roleLabel(a) }}</td>
					<td>
						<span class="toewijzingen-table__type" :class="typeClass(a)">{{ a.toewijzingType || a.type || 'reguliere' }}</span>
					</td>
					<td>{{ a.vanaf || a.geldigVanaf || '—' }}</td>
					<td>{{ a.totEnMet || a.geldigTotEnMet || '—' }}</td>
					<td>
						<NcButton size="small" @click="openEnd(a)">
							{{ t('procest', 'End') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<AddAssignmentDialog
			v-if="addOpen"
			:role-options="roleOptions"
			@save="onAdd"
			@close="addOpen = false" />

		<EndAssignmentDialog
			v-if="ending"
			:assignment="ending"
			@save="onEnd"
			@close="ending = null" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import Plus from 'vue-material-design-icons/Plus.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AddAssignmentDialog from '../../../dialogs/AddAssignmentDialog.vue'
import EndAssignmentDialog from '../../../dialogs/EndAssignmentDialog.vue'

export default {
	name: 'MandaatToewijzingenTable',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, Plus, AccountMultiple, AddAssignmentDialog, EndAssignmentDialog },
	props: {
		assignments: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
		roleOptions: { type: Array, default: () => [] },
	},
	emits: ['reload'],
	data() {
		return {
			addOpen: false,
			ending: null,
		}
	},
	methods: {
		t,
		isWaarnemer(a) {
			const tt = (a.toewijzingType || a.type || '').toLowerCase()
			return tt === 'waarnemer' || tt === 'plaatsvervanger'
		},
		typeClass(a) {
			return this.isWaarnemer(a)
				? 'toewijzingen-table__type--waarnemer'
				: 'toewijzingen-table__type--regular'
		},
		roleLabel(a) {
			const opt = this.roleOptions.find(o => o.id === (a.rolId || a.role))
			return opt ? opt.label : (a.rolLabel || a.rolId || a.role || '—')
		},
		openEnd(a) {
			this.ending = a
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async onAdd(payload) {
			try {
				await axios.post(generateUrl('/apps/procest/api/mandate/toewijzingen'), payload)
				this.addOpen = false
				this.$emit('reload')
			} catch (e) { /* dialog stays open */ }
		},
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async onEnd(endDate) {
			try {
				await axios.patch(
					generateUrl('/apps/procest/api/mandate/toewijzingen/' + encodeURIComponent(this.ending.id)),
					{ totEnMet: endDate },
				)
				this.ending = null
				this.$emit('reload')
			} catch (e) { /* dialog stays open */ }
		},
	},
}
</script>

<style scoped>
.toewijzingen-table__toolbar {
	margin-bottom: 12px;
}

.toewijzingen-table__table {
	width: 100%;
	border-collapse: collapse;
}

.toewijzingen-table__table th,
.toewijzingen-table__table td {
	padding: 8px 10px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.toewijzingen-table__row--waarnemer {
	background: var(--color-background-dark);
	font-style: italic;
}

.toewijzingen-table__type {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 8px;
}

.toewijzingen-table__type--regular {
	background: var(--color-primary-light);
}

.toewijzingen-table__type--waarnemer {
	background: var(--color-warning);
	color: var(--color-main-background);
}
</style>
