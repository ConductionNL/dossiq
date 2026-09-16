<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The AVG panel on a data subject request case.

  It shows what OpenRegister reported and offers the acts dossiq drives: take
  the erasure preview, run the approved erasure, ask for the subject's own
  export, and take that export while the platform still serves it.

  🔴 THE PROTECTED LIST IS THE POINT OF THIS PANEL. A count of what cannot be
  erased is not an answer to a data subject; the ground, the basis and what can
  still be done are. So the protected items are rendered in full, one row each,
  and the counts sit above them rather than instead of them.

  Nothing here computes an erasure. Every number and every ground is a value
  the server read back from OpenRegister's preview.

  @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
-->
<template>
	<div class="dsr-tab">
		<NcNoteCard v-if="refusal" type="warning">
			{{ refusal.message }}
			<span class="dsr-tab__rule">{{ refusal.rule }}</span>
		</NcNoteCard>

		<section v-if="isErasure" class="dsr-tab__section">
			<h3>{{ t('dossiq', 'Erasure') }}</h3>

			<NcEmptyContent
				v-if="!hasPreview"
				:name="t('dossiq', 'No preview yet')"
				:description="
					t(
						'dossiq',
						'Ask the platform what erasing this person would touch.',
					)
				" />

			<ul v-else class="dsr-tab__counts">
				<li>{{ t('dossiq', 'Erasable') }}: {{ total('erasable') }}</li>
				<li>
					{{ t('dossiq', 'To pseudonymise') }}:
					{{ total('pseudonymised') }}
				</li>
				<li>{{ t('dossiq', 'Protected') }}: {{ total('protected') }}</li>
			</ul>

			<div v-if="protectedItems.length" class="dsr-tab__protected">
				<h4>{{ t('dossiq', 'Protected from erasure') }}</h4>
				<ul>
					<li v-for="(item, index) in protectedItems" :key="index">
						<strong>{{ item.name }}</strong>
						<span>{{ item.ground }}</span>
						<span>{{ item.basis }}</span>
						<em>{{ item.action }}</em>
					</li>
				</ul>
			</div>

			<NcNoteCard
				v-if="outcome"
				:type="outcome.complete ? 'success' : 'warning'">
				{{ outcomeSentence }}
			</NcNoteCard>

			<div class="dsr-tab__actions">
				<NcButton :disabled="busy" @click="takePreview">
					{{ t('dossiq', 'Take the preview') }}
				</NcButton>
				<NcButton
					v-if="hasPreview"
					variant="warning"
					:disabled="busy"
					@click="takeRun">
					{{ t('dossiq', 'Run the approved erasure') }}
				</NcButton>
			</div>
		</section>

		<NcEmptyContent
			v-else-if="!isRequest"
			:name="t('dossiq', 'Not a data subject request')"
			:description="
				t(
					'dossiq',
					'This case answers something else, so there is nothing to erase or export here.',
				)
			" />

		<section v-else class="dsr-tab__section">
			<h3>{{ t('dossiq', 'Subject export') }}</h3>

			<NcNoteCard v-if="exportState.expired" type="warning">
				{{ t('dossiq', 'This export expired. Ask for it again.') }}
			</NcNoteCard>

			<p v-else-if="exportState.exportId && !exportState.downloadable">
				{{ t('dossiq', 'The platform is still assembling the file.') }}
			</p>

			<div class="dsr-tab__actions">
				<NcButton :disabled="busy" @click="askForExport">
					{{ t('dossiq', 'Ask for the export') }}
				</NcButton>
				<NcButton
					v-if="exportState.downloadable"
					:href="downloadUrl"
					variant="primary">
					{{ t('dossiq', 'Download the export') }}
				</NcButton>
			</div>
		</section>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import {
	fetchSubjectExportState,
	previewErasure,
	refusalOf,
	requestSubjectExport,
	runErasure,
	subjectExportDownloadUrl,
} from '../../../services/dataSubjectRequestApi.js'

export default {
	name: 'DataSubjectRequestTab',

	components: { NcButton, NcEmptyContent, NcNoteCard },

	props: {
		/** The case this panel acts on. */
		objectId: {
			type: String,
			required: true,
		},

		/** The case itself, as the page already loaded it. */
		object: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			busy: false,
			refusal: null,
			counts: this.object?.erasureCounts ?? {},
			protectedItems: this.object?.erasureProtected ?? [],
			outcome:
				this.object?.erasureOutcome?.complete === undefined
					? null
					: this.object.erasureOutcome,
			exportState: {
				exportId: '',
				downloadable: false,
				expiresAt: '',
				expired: false,
			},
		}
	},

	computed: {
		/**
		 * Whether this case is a data subject request at all.
		 *
		 * The tab is declared once on the case page, so it is mounted on every
		 * case. Asking the platform about a case that never named a subject
		 * would be one request per case opened, answering nothing.
		 *
		 * @return {boolean} True when the case names one of the three rights.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		isRequest() {
			return ['inzage', 'correctie', 'verwijdering'].includes(
				this.object?.dataSubjectRequestType,
			)
		},

		/**
		 * Whether this case asks for an erasure rather than access.
		 *
		 * @return {boolean} True for a verwijdering.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		isErasure() {
			return this.object?.dataSubjectRequestType === 'verwijdering'
		},

		/**
		 * Whether a preview has been taken on this case.
		 *
		 * @return {boolean} True once the platform has answered.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		hasPreview() {
			return Object.keys(this.counts ?? {}).length > 0
		},

		/**
		 * Where the platform serves the export.
		 *
		 * @return {string} The absolute url.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		downloadUrl() {
			return subjectExportDownloadUrl(this.exportState.exportId)
		},

		/**
		 * What the run did, in one sentence.
		 *
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		outcomeSentence() {
			if (this.outcome?.complete === true) {
				return t('dossiq', 'The platform reports the erasure complete.')
			}

			const left = ['withheld', 'refused', 'failed'].reduce(
				(sum, bucket) => sum + (this.outcome?.[bucket]?.length ?? 0),
				0,
			)

			return t(
				'dossiq',
				'The erasure did not finish. {count} records still hold this person.',
				{ count: left },
			)
		},
	},

	/**
	 * Read the export state, but only on a case that asked for one.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	mounted() {
		if (this.isRequest && !this.isErasure) {
			this.loadExportState()
		}
	},

	methods: {
		t,

		/**
		 * Take the erasure preview and show what the platform reported.
		 *
		 * @return {Promise<void>} When the panel has been updated.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		async takePreview() {
			await this.act(async () => {
				const preview = await previewErasure(this.objectId)
				this.counts = preview?.report?.counts ?? {}
				this.protectedItems = preview?.report?.protected ?? []
				this.outcome = null
			})
		},

		/**
		 * Run the erasure this case has approved.
		 *
		 * @return {Promise<void>} When the outcome has been shown.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		async takeRun() {
			await this.act(async () => {
				this.outcome = await runErasure(this.objectId)
			})
		},

		/**
		 * Ask the platform for the subject's own export.
		 *
		 * @return {Promise<void>} When the export has been asked for.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		async askForExport() {
			await this.act(async () => {
				await requestSubjectExport(this.objectId)
				this.exportState = await fetchSubjectExportState(this.objectId)
			})
		},

		/**
		 * Ask the platform whether the export can still be taken.
		 *
		 * @return {Promise<void>} When the state has been read.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		async loadExportState() {
			await this.act(async () => {
				this.exportState = await fetchSubjectExportState(this.objectId)
			})
		},

		/**
		 * Run one act, showing the server's own refusal when it refuses.
		 *
		 * @param {() => Promise<void>} work The act.
		 * @return {Promise<void>} When the act has finished or refused.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		async act(work) {
			this.busy = true
			this.refusal = null
			try {
				await work()
			} catch (error) {
				this.refusal = refusalOf(error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * One bucket of the platform's counts, added over its four kinds of thing.
		 *
		 * @param {string} bucket `erasable`, `pseudonymised` or `protected`.
		 * @return {number} The total.
		 *
		 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
		 */
		total(bucket) {
			return Object.values(this.counts?.[bucket] ?? {}).reduce(
				(sum, value) => sum + Number(value ?? 0),
				0,
			)
		},
	},
}
</script>

<style scoped>
.dsr-tab__section {
	padding: 12px 0;
}

.dsr-tab__counts,
.dsr-tab__protected ul {
	list-style: none;
	padding: 0;
}

.dsr-tab__protected li {
	display: flex;
	flex-direction: column;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.dsr-tab__rule {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.dsr-tab__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}
</style>
