<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="beschikking-detail">
		<div v-if="loading" class="beschikking-detail__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<div v-else-if="!beschikking" class="beschikking-detail__empty">
			<NcEmptyContent :name="t('procest', 'No decision found')" />
		</div>
		<div v-else class="beschikking-detail__body">
			<header class="beschikking-detail__header">
				<h3>{{ beschikking.reference || t('procest', 'Beschikking') }}</h3>
				<span class="beschikking-detail__status">{{ statusLabel }}</span>
			</header>

			<BeschikkingActionBar
				:beschikkingId="decisionId"
				:status="beschikking.currentStatus"
				@updated="onUpdated" />

			<section class="beschikking-detail__section">
				<h4>{{ t('procest', 'Inhoud') }}</h4>
				<dl class="beschikking-detail__meta">
					<dt>{{ t('procest', 'Type') }}</dt>
					<dd>{{ beschikking.decisionType }}</dd>
					<dt>{{ t('procest', 'Sjabloon') }}</dt>
					<dd>{{ beschikking.templateId }}</dd>
					<dt>{{ t('procest', 'Onderwerp') }}</dt>
					<dd>
						{{
							(beschikking.decision && beschikking.decision.subject)
							|| '—'
						}}
					</dd>
					<dt>{{ t('procest', 'Motivering') }}</dt>
					<dd>{{ beschikking.rationale || '—' }}</dd>
				</dl>
			</section>

			<section v-if="hasMandaat" class="beschikking-detail__section">
				<h4>{{ t('procest', 'Mandaat') }}</h4>
				<dl class="beschikking-detail__meta">
					<dt>{{ t('procest', 'Niveau') }}</dt>
					<!-- Outer key renamed; `mandaatNiveau` is NESTED inside it, so it
					     lives in that column's JSON rather than as a column of its own
					     and is deliberately not renamed here. -->
					<dd>{{ beschikking.mandateGranted.mandateLevel }}</dd>
					<dt>{{ t('procest', 'Approved by') }}</dt>
					<dd>{{ beschikking.mandateGranted.approvedBy }}</dd>
				</dl>
			</section>

			<section v-if="hasHandtekening" class="beschikking-detail__section">
				<h4>{{ t('procest', 'Handtekening') }}</h4>
				<dl class="beschikking-detail__meta">
					<dt>{{ t('procest', 'TSP-aanbieder') }}</dt>
					<dd>{{ beschikking.signature.tspProvider }}</dd>
					<dt>{{ t('procest', 'Validatierapport') }}</dt>
					<dd>{{ beschikking.signature.validationRapportId }}</dd>
				</dl>
			</section>

			<section v-if="hasVerzending" class="beschikking-detail__section">
				<h4>{{ t('procest', 'Verzending') }}</h4>
				<dl class="beschikking-detail__meta">
					<dt>{{ t('procest', 'Kanaal') }}</dt>
					<dd>{{ beschikking.dispatch.notificationChannel }}</dd>
					<dt>{{ t('procest', 'Bezwaartermijn eindigt') }}</dt>
					<dd>{{ beschikking.objectionTermEndDate || '—' }}</dd>
				</dl>
			</section>

			<section v-if="hasArchief" class="beschikking-detail__section">
				<h4>{{ t('procest', 'Archief') }}</h4>
				<dl class="beschikking-detail__meta">
					<dt>{{ t('procest', 'Archief-id') }}</dt>
					<dd>{{ beschikking.archive.archiveId }}</dd>
					<dt>{{ t('procest', 'Vernietigingsdatum') }}</dt>
					<dd>{{ beschikking.archive.destruction_date }}</dd>
				</dl>
			</section>
		</div>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import BeschikkingActionBar from './BeschikkingActionBar.vue'
import { getBeschikking } from '../../../services/beschikkingApi.js'

const STATUS_LABELS = {
	ontwerp: 'Draft ruling',
	'akkoord-mandaat': 'Approved (mandate)',
	ondertekend: 'Signed',
	verzonden: 'Sent',
	'ontvangen-bevestiging': 'Receipt confirmation',
	gearchiveerd: 'Archived',
}

export default {
	name: 'BeschikkingDetailView',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
		BeschikkingActionBar,
	},

	props: {
		decisionId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			beschikking: null,
			loading: true,
		}
	},

	computed: {
		statusLabel() {
			const status = this.beschikking ? this.beschikking.currentStatus : ''
			return t('procest', STATUS_LABELS[status] || status)
		},

		hasMandaat() {
			return !!(
				this.beschikking
				&& this.beschikking.mandateGranted
				&& this.beschikking.mandateGranted.approvedBy
			)
		},

		hasHandtekening() {
			return !!(
				this.beschikking
				&& this.beschikking.signature
				&& this.beschikking.signature.tspProvider
			)
		},

		hasVerzending() {
			return !!(
				this.beschikking
				&& this.beschikking.dispatch
				&& this.beschikking.dispatch.notificationChannel
			)
		},

		hasArchief() {
			return !!(
				this.beschikking
				&& this.beschikking.archive
				&& this.beschikking.archive.archiveId
			)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.beschikking = await getBeschikking(this.decisionId)
			} catch (e) {
				this.beschikking = null
			} finally {
				this.loading = false
			}
		},

		onUpdated(updated) {
			if (updated && updated.currentStatus) {
				this.beschikking = updated
			} else {
				this.load()
			}
		},
	},
}
</script>

<style scoped>
.beschikking-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-block-end: 12px;
}

.beschikking-detail__status {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 16px);
	background-color: var(--color-primary-element-light);
}

.beschikking-detail__section {
	margin-block-start: 16px;
}

.beschikking-detail__meta {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
}

.beschikking-detail__meta dt {
	font-weight: bold;
}
</style>
