<template>
	<div>
		<CnDetailPage
			:title="voorstel.onderwerp || t('procest', 'Voorstel')"
			:subtitle="formatType(voorstel.type)"
			:back-route="{ name: 'Voorstellen' }"
			:back-label="t('procest', 'Terug naar overzicht')"
			:loading="loading"
			:sidebar="false">
			<template #actions>
				<NcButton
					v-if="canRegisterBesluit"
					type="primary"
					@click="showBesluitDialog = true">
					{{ t('procest', 'Besluit registreren') }}
				</NcButton>
			</template>

			<!-- Status & Progress -->
			<CnDetailCard :title="t('procest', 'Status & Voortgang')">
				<div class="voorstel-detail__status">
					<span class="voorstel-detail__status-badge" :class="`voorstel-detail__status-badge--${voorstel.status}`">
						{{ formatStatus(voorstel.status) }}
					</span>
				</div>
				<ProgressTimeline
					v-if="steps.length > 0"
					:steps="steps"
					:current-step="voorstel.currentStep || 0"
					:acties="acties" />
			</CnDetailCard>

			<!-- Actions for active parafeerder -->
			<ParafeerActionBar
				v-if="isActiveActor && !isTerminalStatus"
				:voorstel="voorstel"
				:current-step-info="currentStepInfo"
				@action-completed="onActionCompleted" />

			<!-- Resubmit for steller -->
			<CnDetailCard v-if="voorstel.status === 'teruggestuurd' && isSteller" :title="t('procest', 'Teruggestuurd')">
				<p>{{ t('procest', 'Dit voorstel is teruggestuurd. Pas het document aan en dien het opnieuw in.') }}</p>
				<NcButton type="primary" @click="resubmit">
					{{ t('procest', 'Opnieuw indienen') }}
				</NcButton>
			</CnDetailCard>

			<!-- Voorstel Information -->
			<CnDetailCard :title="t('procest', 'Voorstel informatie')">
				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Onderwerp') }}</label>
						<span class="form-value">{{ voorstel.onderwerp || '-' }}</span>
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Type') }}</label>
						<span class="form-value">{{ formatType(voorstel.type) }}</span>
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Steller') }}</label>
						<span class="form-value">{{ voorstel.steller || '-' }}</span>
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Afdeling') }}</label>
						<span class="form-value">{{ voorstel.afdeling || '-' }}</span>
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Portefeuillehouder') }}</label>
						<span class="form-value">{{ voorstel.portefeuillehouder || '-' }}</span>
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Zaak') }}</label>
						<router-link
							v-if="voorstel.case"
							:to="{ name: 'CaseDetail', params: { id: voorstel.case } }">
							{{ t('procest', 'Bekijk zaak') }}
						</router-link>
						<span v-else class="form-value">-</span>
					</div>
				</div>
			</CnDetailCard>

			<!-- Document -->
			<CnDetailCard :title="t('procest', 'Document & Bijlagen')">
				<div v-if="voorstel.document" class="voorstel-detail__document">
					<p>{{ t('procest', 'Voorstel document') }}: {{ voorstel.document }}</p>
				</div>
				<div v-else>
					<p>{{ t('procest', 'Geen document gekoppeld') }}</p>
				</div>
				<div v-if="voorstel.bijlagen && voorstel.bijlagen.length > 0" class="voorstel-detail__bijlagen">
					<h4>{{ t('procest', 'Bijlagen') }} ({{ voorstel.bijlagen.length }})</h4>
					<ul>
						<li v-for="(bijlage, idx) in voorstel.bijlagen" :key="idx">
							{{ bijlage }}
						</li>
					</ul>
				</div>
			</CnDetailCard>

			<!-- Audit Trail -->
			<CnDetailCard :title="t('procest', 'Parafeerhistorie')">
				<AuditTrail :acties="acties" :loading="loadingActies" />
			</CnDetailCard>
		</CnDetailPage>

		<BesluitRegistration
			v-if="showBesluitDialog"
			:voorstel="voorstel"
			@close="showBesluitDialog = false"
			@registered="onBesluitRegistered" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import ProgressTimeline from './components/ProgressTimeline.vue'
import ParafeerActionBar from './components/ParafeerActionBar.vue'
import AuditTrail from './components/AuditTrail.vue'
import BesluitRegistration from './components/BesluitRegistration.vue'
import { useObjectStore } from '../../store/modules/object.js'

const STATUS_LABELS = {
	concept: 'Concept',
	in_parafering: 'In parafering',
	ter_accordering: 'Ter accordering',
	geaccordeerd: 'Geaccordeerd',
	aangeboden: 'Aangeboden',
	besloten: 'Besloten',
	gearchiveerd: 'Gearchiveerd',
	teruggestuurd: 'Teruggestuurd',
}

const TYPE_LABELS = {
	dt_advies: 'DT-advies',
	collegeadvies: 'Collegeadvies',
	raadsvoorstel: 'Raadsvoorstel',
}

export default {
	name: 'VoorstelDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		ProgressTimeline,
		ParafeerActionBar,
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
			voorstel: {},
			acties: [],
			showBesluitDialog: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		steps() {
			if (this.voorstel.routeSnapshot) {
				try {
					return typeof this.voorstel.routeSnapshot === 'string'
						? JSON.parse(this.voorstel.routeSnapshot)
						: this.voorstel.routeSnapshot
				} catch {
					return []
				}
			}
			return []
		},
		currentStepInfo() {
			if (!this.voorstel.currentStep || !this.steps.length) return null
			return this.steps.find(s => s.order === this.voorstel.currentStep) || null
		},
		currentUserId() {
			return getCurrentUser()?.uid || ''
		},
		isActiveActor() {
			if (!this.currentStepInfo) return false
			return this.currentStepInfo.actor === this.currentUserId
		},
		isSteller() {
			return this.voorstel.steller === this.currentUserId
		},
		isTerminalStatus() {
			return ['besloten', 'gearchiveerd'].includes(this.voorstel.status)
		},
		canRegisterBesluit() {
			return ['geaccordeerd', 'aangeboden'].includes(this.voorstel.status)
		},
	},
	async created() {
		await Promise.all([
			this.loadVoorstel(),
			this.loadActies(),
		])
	},
	methods: {
		async loadVoorstel() {
			this.loading = true
			try {
				this.voorstel = await this.objectStore.fetchOne('voorstel', this.voorstelId)
			} catch (error) {
				console.error('Failed to load voorstel:', error)
			} finally {
				this.loading = false
			}
		},
		async loadActies() {
			this.loadingActies = true
			try {
				const results = await this.objectStore.fetchCollection('parafeeractie', {
					'_filters[voorstel]': this.voorstelId,
					_limit: 100,
					_order: '_self.created',
					_direction: 'asc',
				})
				this.acties = Array.isArray(results) ? results : (results?.results || [])
			} catch (error) {
				console.error('Failed to load acties:', error)
				this.acties = []
			} finally {
				this.loadingActies = false
			}
		},
		formatType(type) {
			return TYPE_LABELS[type] || type || '-'
		},
		formatStatus(status) {
			return STATUS_LABELS[status] || status || '-'
		},
		async onActionCompleted() {
			await Promise.all([
				this.loadVoorstel(),
				this.loadActies(),
			])
		},
		async resubmit() {
			try {
				const resumeStep = this.voorstel.returnedFromStep || 1
				await this.objectStore.saveObject('voorstel', {
					...this.voorstel,
					status: 'in_parafering',
					currentStep: resumeStep,
				})
				await this.loadVoorstel()
			} catch (error) {
				console.error('Failed to resubmit voorstel:', error)
			}
		},
		async onBesluitRegistered() {
			this.showBesluitDialog = false
			await this.loadVoorstel()
		},
	},
}
</script>

<style scoped>
.voorstel-detail__status {
	margin-bottom: 12px;
}

.voorstel-detail__status-badge {
	display: inline-block;
	padding: 4px 12px;
	border-radius: var(--border-radius-pill);
	font-weight: 600;
}

.voorstel-detail__status-badge--concept { background: var(--color-background-dark); }
.voorstel-detail__status-badge--in_parafering { background: var(--color-primary-element-light); color: var(--color-primary-element); }
.voorstel-detail__status-badge--geaccordeerd { background: var(--color-success-light, #e8f5e9); color: var(--color-success, #2e7d32); }
.voorstel-detail__status-badge--besloten { background: var(--color-success-light, #e8f5e9); color: var(--color-success, #2e7d32); }
.voorstel-detail__status-badge--teruggestuurd { background: var(--color-warning-light, #fff3e0); color: var(--color-warning, #e65100); }

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

.voorstel-detail__bijlagen ul {
	list-style: none;
	padding: 0;
}

.voorstel-detail__bijlagen li {
	padding: 4px 0;
}
</style>
