<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcModal size="large" @close="$emit('close')">
		<div class="dso-case-detail">
			<h2>{{ zaak.title }}</h2>

			<!-- Aanvraag section -->
			<section class="dso-case-detail__section">
				<h3>{{ t('procest', 'Aanvraag') }}</h3>
				<dl>
					<dt>{{ t('procest', 'Procedure type') }}</dt>
					<dd>{{ zaak.procedureType }}</dd>
					<dt>{{ t('procest', 'Bevoegd gezag') }}</dt>
					<dd>{{ zaak.bevoegdGezag }}</dd>
					<dt>{{ t('procest', 'Deadline') }}</dt>
					<dd :class="deadlineClass(zaak.deadlineDatum)">{{ zaak.deadlineDatum }}</dd>
					<dt>{{ t('procest', 'Status') }}</dt>
					<dd>{{ zaak.status }}</dd>
				</dl>
			</section>

			<!-- Activity timeline -->
			<section v-if="activity.length > 0" class="dso-case-detail__section">
				<h3>{{ t('procest', 'Activity') }}</h3>
				<ul class="dso-case-detail__timeline">
					<li v-for="(entry, idx) in activity" :key="idx">
						<strong>{{ entry.type }}</strong> — {{ entry.timestamp }}
						<span v-if="entry.newStatus"> → {{ entry.newStatus }}</span>
					</li>
				</ul>
			</section>

			<!-- Lifecycle actions -->
			<section class="dso-case-detail__actions">
				<h3>{{ t('procest', 'Actions') }}</h3>
				<div class="dso-case-detail__action-bar">
					<NcButton type="secondary" @click="showTransition = true">
						{{ t('procest', 'Change status') }}
					</NcButton>
					<NcButton type="secondary" @click="showBeschikking = true">
						{{ t('procest', 'Generate beschikking') }}
					</NcButton>
					<NcButton type="secondary" @click="showSamenwerking = true">
						{{ t('procest', 'Initiate samenwerking') }}
					</NcButton>
					<NcButton type="secondary" @click="showDoorstuur = true">
						{{ t('procest', 'Doorsturen') }}
					</NcButton>
				</div>
			</section>

			<!-- Transition form (inline) -->
			<div v-if="showTransition" class="dso-case-detail__inline-form">
				<NcSelect
					v-model="transitionStatus"
					:label="t('procest', 'New status')"
					:options="statusOptions" />
				<NcTextField
					:value="transitionToelichting"
					:label="t('procest', 'Toelichting')"
					@update:value="v => transitionToelichting = v" />
				<NcButton type="primary" :disabled="!transitionStatus" @click="doTransition">
					{{ t('procest', 'Apply') }}
				</NcButton>
				<NcButton type="tertiary" @click="showTransition = false">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</div>

			<BeschikkingDialog
				v-if="showBeschikking"
				:case-id="zaak.id"
				@close="showBeschikking = false"
				@done="onActionDone" />

			<SamenwerkverzoekDialog
				v-if="showSamenwerking"
				:case-id="zaak.id"
				@close="showSamenwerking = false"
				@done="onActionDone" />

			<DoorstuurDialog
				v-if="showDoorstuur"
				:case-id="zaak.id"
				@close="showDoorstuur = false"
				@done="onActionDone" />
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import BeschikkingDialog from './BeschikkingDialog.vue'
import DoorstuurDialog from './DoorstuurDialog.vue'
import SamenwerkverzoekDialog from './SamenwerkverzoekDialog.vue'

export default {
	name: 'DsoCaseDetail',
	components: {
		NcButton,
		NcModal,
		NcSelect,
		NcTextField,
		BeschikkingDialog,
		DoorstuurDialog,
		SamenwerkverzoekDialog,
	},

	props: {
		zaak: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'updated'],

	data() {
		return {
			showTransition: false,
			showBeschikking: false,
			showSamenwerking: false,
			showDoorstuur: false,
			transitionStatus: null,
			transitionToelichting: '',
			statusOptions: [
				{ label: t('procest', 'Ingediend'), value: 'ingediend' },
				{ label: t('procest', 'In behandeling'), value: 'in_behandeling' },
				{ label: t('procest', 'Verleend'), value: 'verleend' },
				{ label: t('procest', 'Geweigerd'), value: 'geweigerd' },
				{ label: t('procest', 'Ingetrokken'), value: 'ingetrokken' },
			],
		}
	},

	computed: {
		activity() {
			const raw = this.zaak.activity
			if (Array.isArray(raw)) return raw
			try { return JSON.parse(raw) } catch { return [] }
		},
	},

	methods: {
		t,

		deadlineClass(deadlineDatum) {
			if (!deadlineDatum) return ''
			const today = new Date()
			const deadline = new Date(deadlineDatum)
			const diffDays = Math.ceil((deadline - today) / (1000 * 60 * 60 * 24))
			if (diffDays < 0) return 'deadline-overdue'
			if (diffDays <= 5) return 'deadline-critical'
			if (diffDays <= 14) return 'deadline-warning'
			return ''
		},

		async doTransition() {
			if (!this.transitionStatus?.value) return
			try {
				const url = generateUrl('/apps/procest/api/dso/cases/' + encodeURIComponent(this.zaak.id) + '/transition')
				await axios.post(url, {
					status: this.transitionStatus.value,
					toelichting: this.transitionToelichting,
				})
				this.showTransition = false
				this.$emit('updated')
			} catch (err) {
				console.error('DsoCaseDetail: transition error', err)
			}
		},

		onActionDone() {
			this.showBeschikking = false
			this.showSamenwerking = false
			this.showDoorstuur = false
			this.$emit('updated')
		},
	},
}
</script>

<style scoped>
.dso-case-detail {
	padding: 16px;
}
.dso-case-detail__section {
	margin-bottom: 20px;
}
.dso-case-detail__section dl {
	display: grid;
	grid-template-columns: 1fr 2fr;
	gap: 4px 12px;
}
.dso-case-detail__section dt {
	font-weight: bold;
}
.dso-case-detail__timeline {
	list-style: disc;
	padding-left: 20px;
}
.dso-case-detail__action-bar {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
.dso-case-detail__inline-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 12px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: 4px;
}
.deadline-warning { color: var(--color-warning); font-weight: bold; }
.deadline-critical { color: var(--color-error); font-weight: bold; }
.deadline-overdue { color: var(--color-error); font-weight: bold; text-decoration: underline; }
</style>
