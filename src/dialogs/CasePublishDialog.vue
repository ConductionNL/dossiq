<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Publish a decided case: what goes out, grouped by how each document was
  assessed, then one press. The groups, their notes and which group never goes
  along are configuration the case type's manifest action carries; the dialog
  only counts and posts.

  @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md
-->
<template>
	<CnDialog
		:name="t('dossiq', title)"
		:eyebrow="eyebrow"
		:subtitle="subtitle ? t('dossiq', subtitle) : ''"
		width="wizard"
		:noClose="busy"
		data-testid="case-publish-dialog"
		@closing="$emit('close')">
		<div v-if="!published" class="case-publish">
			<h3 class="case-publish__heading">
				{{ t('dossiq', 'What is published') }}
			</h3>

			<div v-if="kase" class="case-publish__decision">
				<Gavel :size="20" class="case-publish__decision-icon" />
				<span class="case-publish__decision-text">
					<strong>{{ kase.title }}</strong>
					<span class="case-publish__muted">{{ [kase.identifier, kase.resultPublicLabel].filter(Boolean).join(' · ') }}</span>
				</span>
			</div>

			<div
				v-for="group in groups"
				:key="group.value"
				class="case-publish__group"
				:class="{ 'case-publish__group--excluded': group.excluded }"
				:data-testid="'case-publish-group-' + group.value">
				<span class="case-publish__badge" :class="'case-publish__badge--' + group.tone">{{ t('dossiq', group.label) }}</span>
				<span v-if="group.note" class="case-publish__muted">{{ t('dossiq', group.note) }}</span>
				<span class="case-publish__spacer" />
				<strong class="case-publish__count">{{ n('dossiq', '%n document', '%n documents', group.count) }}</strong>
			</div>

			<p v-if="countsError" class="case-publish__muted" role="status">
				{{ countsError }}
			</p>

			<NcNoteCard v-if="note" type="info">
				{{ t('dossiq', note) }}
			</NcNoteCard>

			<p
				v-if="error"
				class="case-publish__error"
				role="alert"
				data-testid="case-publish-error">
				{{ error }}
			</p>
		</div>

		<p v-else role="status" data-testid="case-publish-done">
			{{ t('dossiq', 'The decision is published.') }}
		</p>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ published ? t('dossiq', 'Close') : t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!published"
				variant="primary"
				:disabled="busy || publishUrl === ''"
				data-testid="case-publish-confirm"
				@click="publish">
				<template #icon>
					<PublishIcon :size="20" />
				</template>
				{{ t('dossiq', 'Publish') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import PublishIcon from 'vue-material-design-icons/Publish.vue'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CasePublishDialog',

	components: {
		CnDialog,
		Gavel,
		NcButton,
		NcNoteCard,
		PublishIcon,
	},

	props: {
		/** The case; arrives as the unresolved `@objectId` token, so the route answers. */
		caseId: {
			type: String,
			default: '',
		},

		/** The dialog title, an i18n key. */
		title: {
			type: String,
			default: 'Publish',
		},

		/** The act named in the context line above the title, an i18n key. */
		act: {
			type: String,
			default: 'Publication',
		},

		/** The sentence under the title, an i18n key. */
		subtitle: {
			type: String,
			default: '',
		},

		/** Where the publication is posted; `{caseId}` is filled in. Empty disables publishing. */
		publishUrl: {
			type: String,
			default: '',
		},

		/** Where the assessments are read: `{ register, schema, caseField, classificationField }`. */
		assessments: {
			type: Object,
			default: () => ({}),
		},

		/** The groups: `{ value, label, note?, tone?, excluded? }`, labels and notes as i18n keys. */
		classifications: {
			type: Array,
			default: () => [],
		},

		/** An i18n key shown as a note under the groups. */
		note: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			kase: null,
			tally: {},
			countsError: '',
			busy: false,
			error: '',
			published: false,
		}
	},

	computed: {
		/**
		 * @return {string} The case this dialog acts on.
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md
		 */
		targetCaseId() {
			const given = String(this.caseId || '')
			return given !== '' && !given.startsWith('@') ? given : String(this.$route?.params?.id ?? '')
		},

		/**
		 * @return {string} The context line above the title.
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md
		 */
		eyebrow() {
			return caseEyebrow(t('dossiq', this.act), this.kase)
		},

		/**
		 * @return {Array<object>} Each configured group with its count.
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md
		 */
		groups() {
			return this.classifications.map((option) => ({
				value: String(option?.value ?? ''),
				label: String(option?.label ?? option?.value ?? ''),
				note: String(option?.note ?? ''),
				tone: String(option?.tone ?? 'neutral'),
				excluded: option?.excluded === true,
				count: this.tally[String(option?.value ?? '')] ?? 0,
			}))
		},
	},

	async mounted() {
		this.loadCounts()
		this.kase = await fetchCaseSummary(this.targetCaseId)
	},

	methods: {
		t,
		n,

		/**
		 * Count the assessed documents per classification.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md
		 */
		async loadCounts() {
			const source = this.assessments || {}
			if (!source.register || !source.schema || this.targetCaseId === '') {
				return
			}
			const field = source.classificationField || 'classification'
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/openregister/api/objects/${encodeURIComponent(source.register)}/${encodeURIComponent(source.schema)}`),
					{ params: { [source.caseField || 'case']: this.targetCaseId, _limit: 500 } },
				)
				const tally = {}
				for (const row of data?.results || []) {
					const value = String(row?.[field] ?? '')
					tally[value] = (tally[value] ?? 0) + 1
				}
				this.tally = tally
			} catch {
				this.countsError = t('dossiq', 'The number of documents per assessment could not be read.')
			}
		},

		/**
		 * Publish, and keep the dialog open with the server's sentence on a refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md
		 */
		async publish() {
			this.busy = true
			this.error = ''
			try {
				await axios.post(generateUrl(this.publishUrl.replace('{caseId}', encodeURIComponent(this.targetCaseId))), {})
				this.published = true
				emit(PAGE_REFRESH, {})
			} catch (failure) {
				const data = failure?.response?.data
				this.error = String(data?.message || data?.error || t('dossiq', 'The decision could not be published.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-publish {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.case-publish__heading {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
}

.case-publish__decision,
.case-publish__group {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.case-publish__group--excluded {
	background: var(--color-background-hover);
}

.case-publish__decision-icon {
	color: var(--color-primary-element);
}

.case-publish__decision-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.case-publish__muted {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.case-publish__spacer {
	flex: 1;
}

.case-publish__count {
	font-size: 13px;
}

.case-publish__badge {
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 13px;
	font-weight: 600;
	background: var(--color-background-dark);
}

.case-publish__badge--success {
	background: var(--color-success-light, var(--color-background-dark));
	color: var(--color-success-text);
}

.case-publish__badge--warning {
	background: var(--color-warning-light, var(--color-background-dark));
	color: var(--color-warning-text);
}

.case-publish__badge--error {
	background: var(--color-error-light, var(--color-background-dark));
	color: var(--color-error-text);
}

.case-publish__error {
	margin: 0;
	color: var(--color-error-text);
}
</style>
