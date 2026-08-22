<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -->
<template>
	<div class="vergadering-detail">
		<CnDetailPage
			:title="t('dossiq', 'Vergadering')"
			:subtitle="meetingDate"
			:loading="loading"
			:sidebar="false">
			<CnDetailCard
				v-for="agendaCase in cases"
				:key="agendaCase.id"
				:title="
					(agendaCase.agendanummer || '') + ' ' + (agendaCase.title || '')
				">
				<div class="vergadering-detail__form">
					<label :for="'besluittype-' + agendaCase.id">{{
						t('dossiq', 'Decision type')
					}}</label>
					<NcSelect
						v-model="forms[agendaCase.id].decisionType"
						:inputId="'besluittype-' + agendaCase.id"
						:inputLabel="t('dossiq', 'Decision type')"
						:options="decisionTypes" />

					<label :for="'stem-' + agendaCase.id">{{
						t('dossiq', 'Stemuitslag')
					}}</label>
					<input
						:id="'stem-' + agendaCase.id"
						v-model="forms[agendaCase.id].stemuitslag"
						type="text"
						:placeholder="
							t('dossiq', 'e.g. Unanimous or 23 for / 8 against')
						" />

					<label :for="'leden-' + agendaCase.id">{{
						t('dossiq', 'Aanwezige leden (komma-gescheiden)')
					}}</label>
					<input
						:id="'leden-' + agendaCase.id"
						v-model="forms[agendaCase.id].attendees"
						type="text" />

					<label :for="'toelichting-' + agendaCase.id">{{
						t('dossiq', 'Explanation')
					}}</label>
					<textarea
						:id="'toelichting-' + agendaCase.id"
						v-model="forms[agendaCase.id].explanation"
						rows="3" />

					<div class="vergadering-detail__actions">
						<NcButton
							type="primary"
							:disabled="!isComplete(agendaCase.id)"
							@click="recordBesluit(agendaCase)">
							{{ t('dossiq', 'Besluit vastleggen') }}
						</NcButton>
						<NcButton type="secondary" @click="aanhouden(agendaCase)">
							{{ t('dossiq', 'Aanhouden') }}
						</NcButton>
					</div>
				</div>
			</CnDetailCard>
		</CnDetailPage>
	</div>
</template>

<script>
import { CnDetailCard, CnDetailPage } from '@conduction/nextcloud-vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'VergaderingDetailView',
	components: {
		NcButton,
		NcSelect,
		CnDetailPage,
		CnDetailCard,
	},

	props: {
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			cases: [],
			forms: {},
			meetingDate: '',
			decisionTypes: ['Goedgekeurd', 'Verworpen', 'Aangehouden'],
		}
	},

	computed: {
		/** @spec openspec/specs/besluitvorming-workflow/spec.md */
		objectStore() {
			return useObjectStore()
		},
	},

	created() {
		this.loadCases()
	},

	methods: {
		/**
		 * Load all geagendeerde cases for the meeting.
		 *
		 * @spec openspec/specs/besluitvorming-workflow/spec.md
		 */
		async loadCases() {
			this.loading = true
			try {
				const results = await this.objectStore.fetchCollection('case', {
					'_filters[status]': 'geagendeerd',
					'_filters[vergaderdatum]': this.id,
					_limit: 100,
				})
				this.cases = Array.isArray(results)
					? results
					: results?.results || []
				this.meetingDate = this.cases[0]?.vergaderdatum || this.id
				for (const c of this.cases) {
					this.forms[c.id] = {
						decisionType: '',
						stemuitslag: '',
						attendees: '',
						explanation: '',
					}
				}
			} catch (error) {
				console.error('Failed to load vergadering cases:', error)
				this.cases = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Whether the required fields for a case are filled.
		 *
		 * @param {string} caseId The case UUID.
		 * @return {boolean} True when all required fields are present.
		 */
		isComplete(caseId) {
			const f = this.forms[caseId]
			return !!(f && f.decisionType && f.stemuitslag && f.explanation)
		},

		/**
		 * Create the decision object and transition the case.
		 *
		 * @param {object} agendaCase The case object.
		 * @spec openspec/specs/besluitvorming-workflow/spec.md
		 */
		async recordBesluit(agendaCase) {
			const f = this.forms[agendaCase.id]
			if (!this.isComplete(agendaCase.id)) return
			if (f.decisionType === 'Aangehouden') {
				await this.aanhouden(agendaCase)
				return
			}
			try {
				await this.objectStore.createObject('decision', {
					case: agendaCase.id,
					decisionDate: new Date().toISOString().slice(0, 10),
					governingBody: agendaCase.vergadergremium || '',
					decisionType: f.decisionType,
					explanation:
						f.explanation + ' (Stemuitslag: ' + f.stemuitslag + ')',
				})
				await this.objectStore.createObject('caseProperty', {
					case: agendaCase.id,
					name: 'stemuitslag',
					value: f.stemuitslag,
				})
				await this.recordAttendees(agendaCase.id, f.attendees)
				await this.loadCases()
			} catch (error) {
				console.error('Failed to record decision:', error)
			}
		},

		/**
		 * Record attending members as role objects.
		 *
		 * @param {string} caseId The case UUID.
		 * @param {string} attendees Comma-separated attendee uids.
		 */
		async recordAttendees(caseId, attendees) {
			const list = (attendees || '')
				.split(',')
				.map((a) => a.trim())
				.filter(Boolean)
			for (const person of list) {
				await this.objectStore.createObject('role', {
					case: caseId,
					roleType: 'Aanwezig lid',
					person,
				})
			}
		},

		/**
		 * Defer (aanhouden) — route the case back to Gereed voor agendering.
		 *
		 * @param {object} agendaCase The case object.
		 * @spec openspec/specs/besluitvorming-workflow/spec.md
		 */
		async aanhouden(agendaCase) {
			try {
				await this.objectStore.saveObject('case', {
					...agendaCase,
					status: 'gereed_voor_agendering',
				})
				await this.loadCases()
			} catch (error) {
				console.error('Failed to defer decision:', error)
			}
		},
	},
}
</script>

<style scoped>
.vergadering-detail__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 520px;
}

.vergadering-detail__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}
</style>
