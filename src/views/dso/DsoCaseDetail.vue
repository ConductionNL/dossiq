<!--
  SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
  @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
-->
<template>
	<NcModal :name="t('procest', 'Omgevingsvergunning detail')" size="large" @close="$emit('close')">
		<div class="dso-detail">
			<div class="dso-detail__header">
				<h2>{{ zaak.title }}</h2>
				<span :class="'vth-status vth-status--' + statusSlug">{{ statusLabel }}</span>
			</div>

			<!-- DSO data sections -->
			<div class="dso-detail__sections">
				<!-- Aanvraag section -->
				<section class="dso-section">
					<h3>{{ t('procest', 'Aanvraag') }}</h3>
					<dl class="dso-dl">
						<dt>{{ t('procest', 'Identifier') }}</dt>
						<dd>{{ zaak.identifier || zaak.id }}</dd>
						<dt>{{ t('procest', 'Procedure type') }}</dt>
						<dd>{{ zaak.procedureType || '—' }}</dd>
						<dt>{{ t('procest', 'Bevoegd gezag') }}</dt>
						<dd>{{ zaak.bevoegdGezag || '—' }}</dd>
						<dt>{{ t('procest', 'Deadline') }}</dt>
						<dd :class="deadlineClass">{{ formattedDeadline }}</dd>
						<dt>{{ t('procest', 'Vergunningaanvraag ref') }}</dt>
						<dd>{{ zaak.vergunningaanvraagRef || '—' }}</dd>
					</dl>
				</section>

				<!-- Activiteiten section -->
				<section v-if="vergunningaanvraag" class="dso-section">
					<h3>{{ t('procest', 'Activiteiten') }}</h3>
					<ul v-if="activiteiten.length > 0" class="dso-activiteiten">
						<li v-for="(act, idx) in activiteiten" :key="idx">
							<strong>{{ act.naam || act }}</strong>
							<span v-if="act.regelkwalificatie" class="dso-kwalificatie">
								{{ act.regelkwalificatie }}
							</span>
						</li>
					</ul>
					<p v-else>{{ t('procest', 'No activiteiten available.') }}</p>
				</section>

				<!-- Locatie section -->
				<section v-if="vergunningaanvraag && vergunningaanvraag.locatie" class="dso-section">
					<h3>{{ t('procest', 'Locatie') }}</h3>
					<dl class="dso-dl">
						<dt>{{ t('procest', 'Adres') }}</dt>
						<dd>{{ vergunningaanvraag.locatie.adres || '—' }}</dd>
						<dt>{{ t('procest', 'Gemeente') }}</dt>
						<dd>{{ vergunningaanvraag.locatie.gemeenteNaam || '—' }} ({{ vergunningaanvraag.locatie.gemeenteCode || '—' }})</dd>
					</dl>
				</section>

				<!-- Initiatiefnemer section -->
				<section v-if="vergunningaanvraag && vergunningaanvraag.initiatiefnemer" class="dso-section">
					<h3>{{ t('procest', 'Initiatiefnemer') }}</h3>
					<dl class="dso-dl">
						<dt>{{ t('procest', 'Naam') }}</dt>
						<dd>{{ vergunningaanvraag.initiatiefnemer.naam || '—' }}</dd>
						<dt>{{ t('procest', 'Type') }}</dt>
						<dd>{{ vergunningaanvraag.initiatiefnemer.type || '—' }}</dd>
					</dl>
				</section>

				<!-- Samenwerkverzoeken section -->
				<section class="dso-section">
					<h3>{{ t('procest', 'Samenwerkverzoeken') }}</h3>
					<p v-if="samenwerkverzoeken.length === 0">{{ t('procest', 'No samenwerkverzoeken.') }}</p>
					<ul v-else class="dso-samenwerk-list">
						<li v-for="sw in samenwerkverzoeken" :key="sw.id">
							<strong>{{ sw.aangezochtBevoegdGezag }}</strong>
							— <span :class="'vth-status vth-status--' + sw.status">{{ sw.status }}</span>
							<span v-if="sw.advies"> — {{ sw.advies }}</span>
						</li>
					</ul>
				</section>
			</div>

			<!-- Lifecycle action bar -->
			<div class="dso-detail__actions">
				<h3>{{ t('procest', 'Actions') }}</h3>
				<div class="dso-action-bar">
					<NcButton @click="showTransitionDialog = true">
						{{ t('procest', 'Change status') }}
					</NcButton>
					<NcButton type="secondary" @click="showBeschikkingDialog = true">
						{{ t('procest', 'Generate beschikking') }}
					</NcButton>
					<NcButton type="secondary" @click="showSamenwerkDialog = true">
						{{ t('procest', 'Initiate samenwerking') }}
					</NcButton>
					<NcButton type="secondary" @click="showDoorstuurDialog = true">
						{{ t('procest', 'Forward (doorstuur)') }}
					</NcButton>
				</div>
			</div>

			<!-- Dialogs -->
			<BeschikkingDialog
				v-if="showBeschikkingDialog"
				:case-id="zaak.id"
				@close="showBeschikkingDialog = false"
				@submitted="onBeschikkingSubmitted" />
			<SamenwerkverzoekDialog
				v-if="showSamenwerkDialog"
				:case-id="zaak.id"
				@close="showSamenwerkDialog = false"
				@submitted="onSamenwerkSubmitted" />
			<DoorstuurDialog
				v-if="showDoorstuurDialog"
				:case-id="zaak.id"
				@close="showDoorstuurDialog = false"
				@submitted="onDoorstuurSubmitted" />

			<StatusTransitionDialog
				v-if="showTransitionDialog"
				:case-id="zaak.id"
				@close="showTransitionDialog = false"
				@submitted="onTransitionSubmitted" />
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import BeschikkingDialog from './BeschikkingDialog.vue'
import SamenwerkverzoekDialog from './SamenwerkverzoekDialog.vue'
import DoorstuurDialog from './DoorstuurDialog.vue'
import StatusTransitionDialog from './StatusTransitionDialog.vue'

export default {
	name: 'DsoCaseDetail',
	components: {
		NcModal,
		NcButton,
		BeschikkingDialog,
		SamenwerkverzoekDialog,
		DoorstuurDialog,
		StatusTransitionDialog,
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
			vergunningaanvraag: null,
			samenwerkverzoeken: [],
			showBeschikkingDialog: false,
			showSamenwerkDialog: false,
			showDoorstuurDialog: false,
			showTransitionDialog: false,
		}
	},
	computed: {
		statusLabel() {
			const map = {
				ingediend: t('procest', 'Ingediend'),
				in_behandeling: t('procest', 'In behandeling'),
				verleend: t('procest', 'Verleend'),
				geweigerd: t('procest', 'Geweigerd'),
				ingetrokken: t('procest', 'Ingetrokken'),
			}
			return map[this.zaak.dsoStatus] || this.zaak.dsoStatus || '—'
		},
		statusSlug() {
			return (this.zaak.dsoStatus || 'unknown').replace(/_/g, '-')
		},
		activiteiten() {
			return this.vergunningaanvraag?.activiteiten || []
		},
		formattedDeadline() {
			if (!this.zaak.deadlineDatum) return '—'
			return new Date(this.zaak.deadlineDatum).toLocaleDateString('nl-NL')
		},
		deadlineClass() {
			if (!this.zaak.deadlineDatum) return ''
			const today = new Date()
			const deadline = new Date(this.zaak.deadlineDatum)
			const diffDays = Math.ceil((deadline - today) / (1000 * 60 * 60 * 24))
			if (diffDays < 0) return 'vth-deadline--overdue'
			if (diffDays <= 10) return 'vth-deadline--critical'
			if (diffDays <= 20) return 'vth-deadline--warning'
			return 'vth-deadline--ok'
		},
	},
	mounted() {
		this.loadVergunningaanvraag()
	},
	methods: {
		t,
		async loadVergunningaanvraag() {
			if (!this.zaak.vergunningaanvraagRef) return
			try {
				const url = generateUrl('/apps/openregister/api/objects/dso/vergunningaanvraag/' + encodeURIComponent(this.zaak.vergunningaanvraagRef))
				const res = await axios.get(url)
				this.vergunningaanvraag = res.data
			} catch (e) {
				// Non-fatal: vergunningaanvraag may not be accessible.
			}
		},
		onTransitionSubmitted(data) {
			this.showTransitionDialog = false
			this.$emit('updated', data)
		},
		onBeschikkingSubmitted() {
			this.showBeschikkingDialog = false
		},
		onSamenwerkSubmitted() {
			this.showSamenwerkDialog = false
		},
		onDoorstuurSubmitted() {
			this.showDoorstuurDialog = false
		},
	},
}
</script>

<style scoped>
.dso-detail {
	padding: 20px;
	max-width: 800px;
}
.dso-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}
.dso-detail__sections {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}
.dso-section {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}
.dso-section h3 {
	margin-top: 0;
	margin-bottom: 8px;
	font-size: 1em;
	color: var(--color-primary);
}
.dso-dl {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 12px;
	margin: 0;
}
.dso-dl dt {
	font-weight: bold;
	color: var(--color-text-lighter);
}
.dso-activiteiten {
	list-style: none;
	padding: 0;
	margin: 0;
}
.dso-activiteiten li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}
.dso-kwalificatie {
	font-size: 0.8em;
	padding: 2px 6px;
	background: var(--color-background-dark);
	border-radius: 4px;
}
.dso-samenwerk-list {
	list-style: none;
	padding: 0;
}
.dso-detail__actions {
	margin-top: 20px;
}
.dso-action-bar {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
.dso-transition-form {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.dso-transition-form__actions {
	display: flex;
	gap: 8px;
}
.vth-status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 0.85em;
}
.vth-status--ingediend { background: var(--color-info); color: #fff; }
.vth-status--in-behandeling { background: var(--color-warning); color: #fff; }
.vth-status--verleend { background: var(--color-success); color: #fff; }
.vth-status--geweigerd { background: var(--color-error); color: #fff; }
.vth-status--ingetrokken { background: var(--color-text-lighter); color: #fff; }
.vth-deadline--ok { color: var(--color-success); }
.vth-deadline--warning { color: var(--color-warning); font-weight: bold; }
.vth-deadline--critical { color: var(--color-error); font-weight: bold; }
.vth-deadline--overdue { color: var(--color-error); font-weight: bold; text-decoration: underline; }
</style>
