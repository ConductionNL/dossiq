<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Tender detail view — conditional sections driven by `visibilityFlags()`
  - from `TenderViewModelService` (chain member 06).
  -
  - @spec openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
  -->
<template>
	<div class="lz-tender-detail" data-testid="leverancier-tender-detail">
		<header class="lz-detail-header">
			<router-link to="/leverancier/tenders" class="lz-back" data-testid="leverancier-tender-detail-back">
				← {{ t('procest', 'Terug') }}
			</router-link>
			<h1 v-if="tender">{{ tender.onderwerp || tender.subject || tender.kenmerk || tender.id }}</h1>
		</header>

		<div v-if="loading" data-testid="lz-loading" class="lz-state">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="error || !tender" data-testid="lz-error" class="lz-state lz-state--error" role="alert">
			{{ error || t('procest', 'Aanbesteding niet gevonden.') }}
		</div>

		<section v-else class="lz-detail-body">
			<dl class="lz-fields">
				<dt>{{ t('procest', 'Kenmerk') }}</dt>
				<dd>{{ tender.kenmerk || '—' }}</dd>
				<dt>{{ t('procest', 'Status') }}</dt>
				<dd>
					<span class="lz-badge"
						:class="'lz-badge--' + (tender.badgeColor || 'gray')">
						{{ tender.status }}
					</span>
				</dd>
				<dt>{{ t('procest', 'Gepubliceerd') }}</dt>
				<dd>{{ tender.publishedOn || '—' }}</dd>
				<dt>{{ t('procest', 'Sluitingsdatum') }}</dt>
				<dd>{{ tender.closingDate || '—' }}</dd>
				<dt>{{ t('procest', 'Opdrachtwaarde') }}</dt>
				<dd>{{ tender.contractValue || '—' }}</dd>
			</dl>

			<section v-if="vis.showAward"
				class="lz-section lz-section--award"
				data-testid="leverancier-tender-award-section">
				<h2>{{ t('procest', 'Gunning') }}</h2>
				<p><strong>{{ t('procest', 'Gunningsdatum') }}:</strong> {{ tender.awardedOn || '—' }}</p>
				<a v-if="tender.awardLetterId"
					:href="tender.awardLetterUrl || '#'"
					class="lz-download"
					data-testid="leverancier-tender-award-letter">
					{{ t('procest', 'Download gunningsbrief') }}
				</a>
			</section>

			<section v-if="vis.showRejection"
				class="lz-section lz-section--rejection"
				data-testid="leverancier-tender-rejection-section">
				<h2>{{ t('procest', 'Afwijzing') }}</h2>
				<p><strong>{{ t('procest', 'Reden') }}:</strong> {{ tender.rejectionReason || '—' }}</p>
				<p v-if="tender.appealDeadline">
					<strong>{{ t('procest', 'Beroepstermijn tot') }}:</strong> {{ tender.appealDeadline }}
				</p>
				<a v-if="tender.evaluationReportId"
					:href="tender.evaluationReportUrl || '#'"
					class="lz-download"
					data-testid="leverancier-tender-evaluation-report">
					{{ t('procest', 'Download evaluatierapport') }}
				</a>
			</section>

			<section v-if="vis.showWithdrawal"
				class="lz-section lz-section--withdrawal"
				data-testid="leverancier-tender-withdrawal-section">
				<h2>{{ t('procest', 'Ingetrokken') }}</h2>
				<p>{{ tender.withdrawalReason || t('procest', 'Aanbesteding ingetrokken door opdrachtgever.') }}</p>
			</section>
		</section>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { getTender } from '../../services/leverancierApi.js'

export default {
	name: 'TenderDetail',
	components: { NcLoadingIcon },
	data() {
		return { tender: null, loading: false, error: null }
	},
	computed: {
		supplierRef() {
			return (this.$route.query && this.$route.query.supplierRef) || ''
		},
		tenderId() {
			return this.$route.params.id
		},
		vis() {
			return (this.tender && this.tender.visibility) || {}
		},
	},
	watch: {
		tenderId: 'reload',
	},
	mounted() {
		this.reload()
	},
	methods: {
		async reload() {
			if (!this.supplierRef || !this.tenderId) {
				return
			}
			this.loading = true
			this.error = null
			try {
				this.tender = await getTender(this.supplierRef, this.tenderId)
			} catch (e) {
				this.error = this.t('procest', 'Kon aanbesteding niet laden.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.lz-tender-detail { padding: 20px; max-width: 900px; margin: 0 auto; }
.lz-detail-header { margin-bottom: 16px; }
.lz-back { color: var(--color-primary, #0082c9); text-decoration: none; display: inline-block; margin-bottom: 8px; }
.lz-fields { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; }
.lz-fields dt { font-weight: 600; }
.lz-fields dd { margin: 0; }
.lz-state { padding: 40px 20px; text-align: center; }
.lz-state--error { color: var(--color-error, #c00); }
.lz-section { margin-top: 24px; padding: 16px; border: 1px solid var(--color-border, #ddd); border-radius: 8px; }
.lz-section--award { border-color: #46ba61; }
.lz-section--rejection { border-color: #c4474b; }
.lz-section--withdrawal { border-color: #ed8d04; }
.lz-section h2 { margin-top: 0; font-size: 16px; }
.lz-download { display: inline-block; margin-top: 8px; color: var(--color-primary, #0082c9); text-decoration: none; }
.lz-download:hover { text-decoration: underline; }
.lz-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; color: #fff; }
.lz-badge--gray   { background: #888; }
.lz-badge--blue   { background: #0082c9; }
.lz-badge--green  { background: #46ba61; }
.lz-badge--orange { background: #ed8d04; }
.lz-badge--red    { background: #c4474b; }
</style>
