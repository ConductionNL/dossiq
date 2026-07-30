<!--
 SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog :name="t('procest', 'Omgevingsvergunning — Detail')"
		size="large"
		:can-close="true"
		@close="$emit('close')">
		<template #default>
			<div class="dso-case-detail">
				<!-- Lifecycle action bar -->
				<div class="dso-case-detail__actions">
					<NcButton type="primary"
						@click="showTransitionDialog = true">
						{{ t('procest', 'Status transition') }}
					</NcButton>
					<NcButton type="secondary"
						@click="showBeschikkingDialog = true">
						{{ t('procest', 'Generate beschikking') }}
					</NcButton>
					<NcButton type="secondary"
						@click="showSamenwerkDialog = true">
						{{ t('procest', 'Collaboration') }}
					</NcButton>
					<NcButton type="secondary"
						@click="showDoorstuurDialog = true">
						{{ t('procest', 'Forward') }}
					</NcButton>
				</div>

				<!-- Aanvraag section -->
				<section class="dso-section">
					<h3>{{ t('procest', 'Application') }}</h3>
					<dl class="dso-dl">
						<dt>{{ t('procest', 'Title') }}</dt>
						<dd>{{ zaak.title || '—' }}</dd>
						<dt>{{ t('procest', 'DSO Status') }}</dt>
						<dd>{{ zaak.dsoStatus || '—' }}</dd>
						<dt>{{ t('procest', 'Procedure type') }}</dt>
						<dd>{{ zaak.procedureType || '—' }}</dd>
						<dt>{{ t('procest', 'Deadline') }}</dt>
						<dd>{{ formatDate(zaak.deadlineDatum) }}</dd>
						<dt>{{ t('procest', 'Competent Authority') }}</dt>
						<dd>{{ zaak.bevoegdGezag || '—' }}</dd>
						<dt>{{ t('procest', 'Permit application ref') }}</dt>
						<dd>{{ zaak.vergunningaanvraagRef || '—' }}</dd>
					</dl>
				</section>

				<!-- Decision section (when verleend/geweigerd) -->
				<section v-if="zaak.besluitdatum" class="dso-section">
					<h3>{{ t('procest', 'Decision') }}</h3>
					<dl class="dso-dl">
						<dt>{{ t('procest', 'Decision date') }}</dt>
						<dd>{{ formatDate(zaak.besluitdatum) }}</dd>
						<dt>{{ t('procest', 'Explanation') }}</dt>
						<dd>{{ zaak.dsoToelichting || '—' }}</dd>
					</dl>
				</section>

				<!-- Samenwerkverzoeken section -->
				<section class="dso-section">
					<h3>{{ t('procest', 'Collaboration requests') }}</h3>
					<p v-if="!zaak.samenwerkverzoeken || zaak.samenwerkverzoeken.length === 0">
						{{ t('procest', 'No samenwerkverzoeken linked') }}
					</p>
					<ul v-else>
						<li v-for="swId in zaak.samenwerkverzoeken" :key="swId">
							{{ swId }}
						</li>
					</ul>
				</section>

				<!-- Activity timeline -->
				<section class="dso-section">
					<h3>{{ t('procest', 'Activity timeline') }}</h3>
					<ul v-if="activityEntries.length > 0" class="dso-activity">
						<li v-for="(entry, idx) in activityEntries" :key="idx">
							<span class="dso-activity__timestamp">{{ formatDate(entry.timestamp) }}</span>
							<span class="dso-activity__user">{{ entry.userId }}</span>
							<span>{{ entry.oldStatus }} → {{ entry.newStatus }}</span>
						</li>
					</ul>
					<p v-else>
						{{ t('procest', 'No activity recorded') }}
					</p>
				</section>
			</div>

			<!-- Sub-dialogs -->
			<BeschikkingDialog v-if="showBeschikkingDialog"
			:zaak-id="zaakId"
			@close="showBeschikkingDialog = false"
			@generated="onBeschikkingGenerated" />
		<SamenwerkverzoekDialog v-if="showSamenwerkDialog"
			:zaak-id="zaakId"
			@close="showSamenwerkDialog = false"
			@initiated="onSamenwerkInitiated" />
		<DoorstuurDialog v-if="showDoorstuurDialog"
			:zaak-id="zaakId"
			@close="showDoorstuurDialog = false" />

		<!-- Inline transition form -->
		<div v-if="showTransitionDialog" class="dso-transition-form">
			<h3>{{ t('procest', 'Transition status') }}</h3>
			<NcSelect v-model="transitionStatus"
				:options="transitionOptions"
				:input-label="t('procest', 'New status')"
				input-id="transition-status" />
			<NcTextField v-model="transitionToelichting"
				:label="t('procest', 'Explanation')" />
			<NcTextField v-if="requiresBesluitdatum"
				v-model="transitionBesluitdatum"
				:label="t('procest', 'Decision date')"
				type="date" />
			<div class="dso-transition-form__actions">
				<NcButton type="primary" :disabled="!transitionStatus" @click="executeTransition">
					{{ t('procest', 'Confirm') }}
				</NcButton>
				<NcButton type="tertiary" @click="showTransitionDialog = false">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</div>
		</div>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import BeschikkingDialog from './BeschikkingDialog.vue'
import DoorstuurDialog from './DoorstuurDialog.vue'
import SamenwerkverzoekDialog from './SamenwerkverzoekDialog.vue'

export default {
	name: 'DsoCaseDetail',
	components: {
		NcButton,
		NcDialog,
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
	emits: ['close', 'transition'],
	data() {
		return {
			showBeschikkingDialog: false,
			showSamenwerkDialog: false,
			showDoorstuurDialog: false,
			showTransitionDialog: false,
			transitionStatus: null,
			transitionToelichting: '',
			transitionBesluitdatum: '',
			transitionOptions: [
				{ label: t('procest', 'Submitted'), value: 'ingediend' },
				{ label: t('procest', 'In behandeling'), value: 'in_behandeling' },
				{ label: t('procest', 'Granted'), value: 'verleend' },
				{ label: t('procest', 'Refused'), value: 'geweigerd' },
				{ label: t('procest', 'Withdrawn'), value: 'ingetrokken' },
			],
		}
	},
	computed: {
		zaakId() {
			return this.zaak.uuid || this.zaak.id || ''
		},
		activityEntries() {
			try {
				const raw = this.zaak.activity
				if (!raw) {
					return []
				}

				const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw
				return Array.isArray(parsed) ? parsed : []
			} catch {
				return []
			}
		},
		requiresBesluitdatum() {
			const val = this.transitionStatus?.value
			return val === 'verleend' || val === 'geweigerd'
		},
	},
	methods: {
		t,
		formatDate(dateStr) {
			if (!dateStr) {
				return '—'
			}

			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
		async executeTransition() {
			if (!this.transitionStatus) {
				return
			}

			try {
				const payload = {
					newStatus: this.transitionStatus.value,
					toelichting: this.transitionToelichting || undefined,
					besluitdatum: this.transitionBesluitdatum || undefined,
				}
				const { data } = await axios.post(
					generateUrl('/apps/procest/api/dso/cases/' + encodeURIComponent(this.zaakId) + '/transition'),
					payload,
				)
				this.showTransitionDialog = false
				this.$emit('transition', data)
			} catch {
				// Error is shown via Nextcloud toast in a real impl; silent for now.
			}
		},
		onBeschikkingGenerated() {
			this.showBeschikkingDialog = false
		},
		onSamenwerkInitiated(samenwerk) {
			this.showSamenwerkDialog = false
			if (samenwerk?.uuid || samenwerk?.id) {
				this.$emit('transition', {
					...this.zaak,
					samenwerkverzoeken: [...(this.zaak.samenwerkverzoeken || []), samenwerk.uuid || samenwerk.id],
				})
			}
		},
	},
}
</script>

<style scoped>
.dso-case-detail {
	padding: 8px 0;
}

.dso-case-detail__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.dso-section {
	margin-bottom: 20px;
}

.dso-section h3 {
	font-weight: bold;
	margin-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 4px;
}

.dso-dl {
	display: grid;
	grid-template-columns: 180px 1fr;
	gap: 4px 12px;
}

.dso-dl dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.dso-activity {
	list-style: none;
	padding: 0;
}

.dso-activity li {
	display: flex;
	gap: 8px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.dso-activity__timestamp {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.dso-transition-form {
	margin-top: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
}

.dso-transition-form__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}
</style>
