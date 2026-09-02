<template>
	<div>
		<CnDetailPage
			:title="voorstel.subject || t('dossiq', 'Proposal')"
			:subtitle="formatType(voorstel.type)"
			:backRoute="{ name: 'Voorstellen' }"
			:backLabel="t('dossiq', 'Back to overview')"
			:loading="loading"
			:sidebar="false">
			<template #header-actions>
				<NcButton
					v-if="canRegisterBesluit"
					type="primary"
					@click="showBesluitDialog = true">
					{{ t('dossiq', 'Register decision') }}
				</NcButton>
			</template>

			<!-- Status & Progress -->
			<CnDetailCard :title="t('dossiq', 'Status & Progress')">
				<div class="voorstel-detail__status">
					<span
						class="voorstel-detail__status-badge"
						:class="`voorstel-detail__status-badge--${voorstel.status}`">
						{{ formatStatus(voorstel.status) }}
					</span>
				</div>
				<ProgressTimeline
					v-if="steps.length > 0"
					:steps="steps"
					:currentStep="voorstel.currentStep || 0"
					:acties="acties" />
			</CnDetailCard>

			<!--
				The SIGN-OFF SURFACE retired with the local runtime
				(parafering-runtime-to-decidiq). An approver no longer initials,
				skips or returns through a dossiq dialog that advances a local
				chain — the decision app runs the chain, and this page shows
				what it recorded. The sign-off happens in the approver's work
				queue (the decision app projects each step onto a task) and in
				the decision app's own surfaces.
			-->

			<!-- Parafering history (recorded from the decision app's conclusion). -->
			<CnDetailCard :title="t('dossiq', 'Parafering history')">
				<ParafeerActieTimeline :voorstelId="voorstel.id || voorstelId" />
			</CnDetailCard>

			<!-- Returned notice for the steller -->
			<CnDetailCard
				v-if="voorstel.status === 'teruggestuurd' && isSteller"
				:title="t('dossiq', 'Returned')">
				<p>
					{{
						t(
							'dossiq',
							'This proposal has been returned. Adjust the document; it re-enters parafering through the case workflow.',
						)
					}}
				</p>
			</CnDetailCard>

			<!-- Voorstel Information -->
			<CnDetailCard :title="t('dossiq', 'Proposal information')">
				<div class="form-row">
					<div class="form-group">
						<label>{{ t('dossiq', 'Onderwerp') }}</label>
						<span class="form-value">{{ voorstel.subject || '-' }}</span>
					</div>
					<div class="form-group">
						<label>{{ t('dossiq', 'Type') }}</label>
						<span class="form-value">{{
							formatType(voorstel.type)
						}}</span>
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label>{{ t('dossiq', 'Drafter') }}</label>
						<span class="form-value">{{ voorstel.author || '-' }}</span>
					</div>
					<div class="form-group">
						<label>{{ t('dossiq', 'Department') }}</label>
						<span class="form-value">{{
							voorstel.department || '-'
						}}</span>
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label>{{ t('dossiq', 'Portfolio holder') }}</label>
						<span class="form-value">{{
							voorstel.portfolioHolder || '-'
						}}</span>
					</div>
					<div class="form-group">
						<label>{{ t('dossiq', 'Case') }}</label>
						<router-link
							v-if="voorstel.case"
							:to="{
								name: 'CaseDetail',
								params: { id: voorstel.case },
							}">
							{{ t('dossiq', 'View case') }}
						</router-link>
						<span v-else class="form-value">-</span>
					</div>
				</div>
			</CnDetailCard>

			<!-- Document -->
			<CnDetailCard :title="t('dossiq', 'Document & Bijlagen')">
				<div v-if="voorstel.document" class="voorstel-detail__document">
					<p>
						{{ t('dossiq', 'Proposal document') }}:
						{{ voorstel.document }}
					</p>
				</div>
				<div v-else>
					<p>{{ t('dossiq', 'No document linked') }}</p>
				</div>
				<div
					v-if="voorstel.attachments && voorstel.attachments.length > 0"
					class="voorstel-detail__bijlagen">
					<h4>
						{{ t('dossiq', 'Attachments') }} ({{
							voorstel.attachments.length
						}})
					</h4>
					<ul>
						<li
							v-for="(bijlage, idx) in voorstel.attachments"
							:key="idx">
							{{ bijlage }}
						</li>
					</ul>
				</div>
			</CnDetailCard>

			<!-- Audit Trail -->
			<CnDetailCard :title="t('dossiq', 'Endorsement history')">
				<AuditTrail :acties="acties" :loading="loadingActies" />
			</CnDetailCard>
		</CnDetailPage>

		<BesluitRegistration
			v-if="showBesluitDialog"
			:voorstel="proposal"
			@close="showBesluitDialog = false"
			@registered="onBesluitRegistered" />
	</div>
</template>

<script>
import { CnDetailCard, CnDetailPage } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton } from '@nextcloud/vue'
import BesluitRegistration from '../../dialogs/BesluitRegistration.vue'
import AuditTrail from './components/AuditTrail.vue'
import ParafeerActieTimeline from './components/ParafeerActieTimeline.vue'
import ProgressTimeline from './components/ProgressTimeline.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'

const STATUS_LABELS = {
	draft: 'Draft',
	in_parafering: 'Awaiting initials',
	ter_accordering: 'Awaiting approval',
	geaccordeerd: 'Approved',
	aangeboden: 'Presented',
	besloten: 'Decided',
	archived: 'Archived',
	teruggestuurd: 'Returned',
}

const TYPE_LABELS = {
	dt_advice: 'Management team advice',
	collegeadvies: 'Executive board advice',
	raadsvoorstel: 'Council proposal',
}

export default {
	name: 'VoorstelDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		ProgressTimeline,
		ParafeerActieTimeline,
		AuditTrail,
		BesluitRegistration,
	},

	props: {
		voorstelId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			loadingActies: true,
			proposal: {},
			acties: [],
			showBesluitDialog: false,
		}
	},

	computed: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		steps() {
			if (this.proposal.routeSnapshot) {
				try {
					return typeof this.proposal.routeSnapshot === 'string'
						? JSON.parse(this.proposal.routeSnapshot)
						: this.proposal.routeSnapshot
				} catch {
					return []
				}
			}
			return []
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		currentUserId() {
			return getCurrentUser()?.uid || ''
		},

		isSteller() {
			return this.proposal.author === this.currentUserId
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		canRegisterBesluit() {
			return ['geaccordeerd', 'aangeboden'].includes(this.proposal.status)
		},
	},

	/** @spec openspec/specs/parafering-actions/spec.md */
	async created() {
		// Widgets can mount before App.vue's initializeStores() resolves the
		// app-config — await it (idempotent) so 'proposal'/'parafeeractie'
		// are registered before the first fetch.
		await initializeStores()
		await Promise.all([this.loadVoorstel(), this.loadActies()])
	},

	methods: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		async loadVoorstel() {
			this.loading = true
			try {
				this.proposal = await this.objectStore.fetchObject(
					'proposal',
					this.voorstelId,
				)
			} catch (error) {
				console.error('Failed to load proposal:', error)
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		async loadActies() {
			this.loadingActies = true
			try {
				const results = await this.objectStore.fetchCollection(
					'parafeeractie',
					{
						'_filters[voorstel]': this.voorstelId,
						_limit: 100,
						_order: '_self.created',
						_direction: 'asc',
					},
				)
				this.acties = Array.isArray(results)
					? results
					: results?.results || []
			} catch (error) {
				console.error('Failed to load acties:', error)
				this.acties = []
			} finally {
				this.loadingActies = false
			}
		},

		/**
		 * @param type
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatType(type) {
			return t('dossiq', TYPE_LABELS[type] || type || '-')
		},

		/**
		 * @param status
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatStatus(status) {
			return t('dossiq', STATUS_LABELS[status] || status || '-')
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		async onBesluitRegistered() {
			this.showBesluitDialog = false
			await this.loadVoorstel()
		},
	},
}
</script>

<style scoped>
.proposal-detail__status {
	margin-bottom: 12px;
}

.proposal-detail__status-badge {
	display: inline-block;
	padding: 4px 12px;
	border-radius: var(--border-radius-pill);
	font-weight: 600;
}

.proposal-detail__status-badge--concept {
	background: var(--color-background-dark);
}

.proposal-detail__status-badge--in_parafering {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.proposal-detail__status-badge--geaccordeerd {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.proposal-detail__status-badge--besloten {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.proposal-detail__status-badge--teruggestuurd {
	background: var(--color-warning-light, #fff3e0);
	color: var(--color-warning, #e65100);
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-group {
	flex: 1;
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.form-value {
	color: var(--color-main-text);
}

.proposal-detail__bijlagen ul {
	list-style: none;
	padding: 0;
}

.proposal-detail__bijlagen li {
	padding: 4px 0;
}
</style>
