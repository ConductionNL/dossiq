<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Offline checklist completion for a single field inspection.
  -
  - Renders the checklist template (loaded from IndexedDB during the daily
  - sync), validates required fields with the pure `validateChecklistAnswers`
  - helper, stores answers atomically in IndexedDB and queues one SyncQueue
  - "create" operation for the ChecklistResult. GPS is tagged per answer.
  -
  - @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
  -->
<template>
	<div class="mio-detail" data-testid="mio-inspectie-detail">
		<header class="mio-detail-head">
			<NcButton type="tertiary" :to="{ name: 'Inspecties' }" data-testid="mio-back">
				{{ t('procest', 'Back') }}
			</NcButton>
			<h1 v-if="inspection">
				{{ inspection.caseRef }}
			</h1>
		</header>

		<div v-if="loading" class="mio-state" data-testid="mio-detail-loading">
			<NcLoadingIcon :size="24" />
		</div>

		<template v-else-if="template">
			<p class="mio-progress" data-testid="mio-progress">
				{{ t('procest', '{done} of {total} questions completed', { done: progress.done, total: progress.total }) }}
			</p>

			<form class="mio-checklist" data-testid="mio-checklist" @submit.prevent="save">
				<fieldset v-for="item in template.items" :key="item.questionId" class="mio-question">
					<legend>
						{{ item.text }}
						<span v-if="item.required" class="mio-required" aria-hidden="true">*</span>
					</legend>

					<select v-if="item.type === 'yes_no'"
						v-model="answers[item.questionId].answer"
						:data-testid="`mio-answer-${item.questionId}`"
						class="mio-input">
						<option value="">
							{{ t('procest', '— choose —') }}
						</option>
						<option value="ja">
							{{ t('procest', 'Yes') }}
						</option>
						<option value="nee">
							{{ t('procest', 'No') }}
						</option>
						<option value="nvt">
							{{ t('procest', 'N/A') }}
						</option>
					</select>

					<input v-else-if="item.type === 'photo_required'"
						type="file"
						accept="image/*"
						capture="environment"
						:data-testid="`mio-photo-${item.questionId}`"
						class="mio-input"
						@change="onPhoto(item.questionId, $event)">

					<textarea v-else
						v-model="answers[item.questionId].answer"
						:data-testid="`mio-answer-${item.questionId}`"
						class="mio-input"
						rows="2" />

					<span v-if="errorFor(item.questionId)" class="mio-error" :data-testid="`mio-error-${item.questionId}`">
						{{ errorFor(item.questionId) }}
					</span>
				</fieldset>

				<NcButton native-type="submit"
					type="primary"
					data-testid="mio-save-checklist"
					:disabled="saving">
					{{ t('procest', 'Save answers offline') }}
				</NcButton>
			</form>
		</template>

		<NcEmptyContent v-else
			data-testid="mio-no-template"
			:name="t('procest', 'Checklist not available offline')"
			:description="t('procest', 'Synchronise the day while online to download this checklist.')" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import { validateChecklistAnswers, checklistProgress, classifyGps } from '../../utils/fieldInspectionHelpers.js'
import { getDb } from '../../store/offlineDb.js'

export default {
	name: 'InspectieDetail',
	components: { NcButton, NcLoadingIcon, NcEmptyContent },
	data() {
		return {
			inspection: null,
			template: null,
			answers: {},
			errors: [],
			loading: true,
			saving: false,
			deviceId: window.localStorage?.getItem('procest-mio-device') || 'device-unknown',
		}
	},
	computed: {
		/**
		 * N/M completion progress from the pure helper.
		 *
		 * @return {{ done: number, total: number }} Progress.
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
		 */
		progress() {
			return checklistProgress(this.template, this.answers)
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * Load the inspection + its checklist template from IndexedDB.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
		 */
		async load() {
			this.loading = true
			try {
				const db = getDb()
				this.inspection = await db.fieldInspection.get(this.$route.params.id)
				const templateRef = this.inspection?.checklistTemplateRef
				if (templateRef) {
					this.template = await db.checklistTemplate.get(templateRef)
				}
				const items = this.template?.items ?? []
				const initial = {}
				for (const item of items) {
					initial[item.questionId] = { answer: '', evidenceRefs: [] }
				}
				this.answers = initial
			} finally {
				this.loading = false
			}
		},
		errorFor(questionId) {
			const e = this.errors.find((x) => x.questionId === questionId)
			return e ? e.message : ''
		},
		/**
		 * Capture the current GPS fix (tagged on every answer).
		 *
		 * @return {Promise<object|null>} The GPS reading or null.
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-gps-geolocation-tagging-on-all-fieldwork
		 */
		async captureGps() {
			if (typeof navigator === 'undefined' || !navigator.geolocation) {
				return null
			}
			return await new Promise((resolve) => {
				navigator.geolocation.getCurrentPosition(
					(pos) => resolve({ lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy }),
					() => resolve(null),
					{ timeout: 8000 },
				)
			})
		},
		/**
		 * Register a captured photo as a FieldEvidence ref on the question.
		 *
		 * @param {string} questionId The question.
		 * @param {Event}  event      The file-input change event.
		 * @return {Promise<void>}
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-photo-capture-with-client-side-compression-and-exif-metadata
		 */
		async onPhoto(questionId, event) {
			const file = event?.target?.files?.[0]
			if (!file) return
			const db = getDb()
			const evidenceId = `evidence-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
			await db.fieldEvidence.put({
				id: evidenceId,
				inspectionRef: this.inspection.id,
				type: 'photo',
				localBlobRef: evidenceId,
				capturedAt: new Date().toISOString(),
				transcriptionStatus: 'not_applicable',
			})
			this.answers[questionId].evidenceRefs.push(evidenceId)
		},
		/**
		 * Validate, store the ChecklistResult atomically, queue the sync op.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
		 */
		async save() {
			const result = validateChecklistAnswers(this.template, this.answers)
			this.errors = result.errors
			if (result.valid === false) {
				return
			}

			this.saving = true
			try {
				const gps = await this.captureGps()
				const gpsClass = classifyGps(gps)
				const now = new Date().toISOString()
				const resultId = `result-${this.inspection.id}-${Date.now()}`
				const items = this.template.items.map((item) => ({
					questionId: item.questionId,
					answer: this.answers[item.questionId].answer,
					evidenceRefs: this.answers[item.questionId].evidenceRefs,
					answeredAt: now,
					gpsAtAnswer: gps ? { ...gps, source: gpsClass.source } : { source: 'sensorless' },
				}))

				const db = getDb()
				await db.transaction('rw', db.checklistResult, db.syncQueue, async () => {
					await db.checklistResult.put({
						id: resultId,
						inspectionRef: this.inspection.id,
						checklistTemplateRef: this.template.id,
						items,
					})
					await db.syncQueue.put({
						id: `sync-${resultId}`,
						deviceId: this.deviceId,
						operationType: 'create',
						targetEntity: 'checklistResult',
						targetId: resultId,
						payload: { inspectionRef: this.inspection.id, checklistTemplateRef: this.template.id, items },
						queuedAt: now,
						attemptCount: 0,
						status: 'pending',
					})
				})

				this.$router.push({ name: 'Inspecties' })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.mio-detail { padding: 16px; max-width: 720px; margin: 0 auto; }
.mio-detail-head { display: flex; align-items: center; gap: 12px; }
.mio-question { border: 1px solid var(--color-border, #ddd); border-radius: var(--border-radius, 6px); padding: 12px; margin-bottom: 12px; }
.mio-input { width: 100%; min-height: 44px; margin-top: 6px; }
.mio-required { color: var(--color-error, #c4291b); }
.mio-error { color: var(--color-error, #c4291b); font-size: 0.85em; display: block; margin-top: 4px; }
.mio-progress { color: var(--color-text-maxcontrast); }
.mio-state { display: flex; justify-content: center; padding: 32px; }
</style>
