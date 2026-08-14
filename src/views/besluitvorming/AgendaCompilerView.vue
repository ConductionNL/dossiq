<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -->
<template>
	<div class="agenda-compiler">
		<CnDetailPage
			:title="t('procest', 'Agenda samenstellen')"
			:subtitle="t('procest', 'Compile the meeting agenda from decisions ready for scheduling')"
			:loading="loading"
			:sidebar="false">
			<template #header-actions>
				<NcButton
					type="secondary"
					:disabled="agenda.length === 0"
					@click="onGenerate">
					{{ t('procest', 'Agenda genereren') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="agenda.length === 0 || !meetingDate"
					@click="onConfirm">
					{{ t('procest', 'Agenda bevestigen') }}
				</NcButton>
			</template>

			<CnDetailCard :title="t('procest', 'Vergadering')">
				<div class="agenda-compiler__controls">
					<label for="bvw-gremium">{{ t('procest', 'Vergadergremium') }}</label>
					<NcSelect
						v-model="gremium"
						input-id="bvw-gremium"
						:input-label="t('procest', 'Vergadergremium')"
						:options="gremiumOptions"
						@update:model-value="loadReadyItems" />
					<label for="bvw-date">{{ t('procest', 'Vergaderdatum') }}</label>
					<input id="bvw-date" v-model="meetingDate" type="date">
				</div>
			</CnDetailCard>

			<div class="agenda-compiler__panels">
				<CnDetailCard :title="t('procest', 'Available for scheduling')" class="agenda-compiler__panel">
					<NcEmptyContent
						v-if="available.length === 0"
						:name="t('procest', 'No available items')"
						:description="t('procest', 'No decisions are ready for scheduling for this body.')" />
					<div
						v-for="item in available"
						:key="item.id"
						class="agenda-compiler__available-item">
						<span>{{ item.title || t('procest', 'Onbenoemd voorstel') }}</span>
						<NcButton type="tertiary" @click="addItem(item)">
							{{ t('procest', 'Toevoegen') }}
						</NcButton>
					</div>
				</CnDetailCard>

				<CnDetailCard
					:title="agendaTitle"
					class="agenda-compiler__panel">
					<NcEmptyContent
						v-if="agenda.length === 0"
						:name="t('procest', 'Lege agenda')"
						:description="t('procest', 'Add items from the list on the left.')" />
					<AgendaItem
						v-for="item in agenda"
						:key="item.id"
						:item="item"
						@set-behandeling="onSetBehandeling" />
				</CnDetailCard>
			</div>
		</CnDetailPage>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcEmptyContent } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import AgendaItem from '../../components/besluitvorming/AgendaItem.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { addToAgenda, confirmAgenda, generateAgenda } from '../../services/besluitvormingApi.js'

export default {
	name: 'AgendaCompilerView',
	components: {
		NcButton,
		NcSelect,
		NcEmptyContent,
		CnDetailPage,
		CnDetailCard,
		AgendaItem,
	},
	data() {
		return {
			loading: false,
			gremium: 'College-besluit',
			gremiumOptions: ['College-besluit', 'Raadsbesluit', 'Mandaatbesluit'],
			meetingDate: '',
			available: [],
			agenda: [],
		}
	},
	computed: {
		/** @spec openspec/specs/besluitvorming-workflow/spec.md */
		objectStore() {
			return useObjectStore()
		},
		agendaTitle() {
			const label = this.meetingDate ? ' ' + this.meetingDate : ''
			return this.t('procest', 'Agenda') + label
		},
	},
	created() {
		this.loadReadyItems()
	},
	methods: {
		/**
		 * Load cases that are ready for agendering for the selected gremium.
		 *
		 * @spec openspec/specs/besluitvorming-workflow/spec.md
		 */
		async loadReadyItems() {
			this.loading = true
			try {
				const gremium = typeof this.gremium === 'string' ? this.gremium : (this.gremium?.label || this.gremium)
				const results = await this.objectStore.fetchCollection('case', {
					'_filters[status]': 'gereed_voor_agendering',
					'_filters[caseTypeTitle]': gremium,
					_limit: 200,
				})
				const rows = Array.isArray(results) ? results : (results?.results || [])
				const taken = new Set(this.agenda.map(i => i.id))
				this.available = rows.filter(r => !taken.has(r.id))
			} catch (error) {
				console.error('Failed to load ready items:', error)
				this.available = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Move an item from the available list into the agenda.
		 *
		 * @param {object} item The case object.
		 */
		async addItem(item) {
			const order = this.agenda.length + 1
			const entry = { ...item, handling: 'hamerstuk', agendanummer: '1.' + order }
			this.agenda.push(entry)
			this.available = this.available.filter(a => a.id !== item.id)
			try {
				await addToAgenda(item.id, 'hamerstuk', order)
			} catch (error) {
				console.error('Failed to persist agenda item:', error)
			}
		},
		/**
		 * Update the classification of an agenda item.
		 *
		 * @param {object} payload The { id, behandeling } payload.
		 * @param payload.id
		 * @param payload.handling
		 */
		async onSetBehandeling({ id, behandeling }) {
			const idx = this.agenda.findIndex(i => i.id === id)
			if (idx === -1) return
			this.agenda[idx].handling = behandeling
			try {
				await addToAgenda(id, behandeling, idx + 1)
			} catch (error) {
				console.error('Failed to update handling:', error)
			}
		},
		/**
		 * Confirm the agenda — transition cases to Geagendeerd.
		 *
		 * @spec openspec/specs/besluitvorming-workflow/spec.md
		 */
		async onConfirm() {
			if (!this.meetingDate || this.agenda.length === 0) return
			try {
				await confirmAgenda('agenda-' + this.meetingDate, this.agenda.map(i => i.id), this.meetingDate)
				this.agenda = []
				await this.loadReadyItems()
			} catch (error) {
				console.error('Failed to confirm agenda:', error)
			}
		},
		/**
		 * Generate the ordered agenda document.
		 *
		 * @spec openspec/specs/besluitvorming-workflow/spec.md
		 */
		async onGenerate() {
			try {
				await generateAgenda(this.agenda.map(i => i.id))
			} catch (error) {
				console.error('Failed to generate agenda document:', error)
			}
		},
	},
}
</script>

<style scoped>
.agenda-compiler__panels {
	display: flex;
	gap: 16px;
}

.agenda-compiler__panel {
	flex: 1;
}

.agenda-compiler__controls {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 420px;
}

.agenda-compiler__available-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}
</style>
