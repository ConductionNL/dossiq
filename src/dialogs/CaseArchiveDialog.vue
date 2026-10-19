<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Archive a case, built to board DqArchiveren: the case, what the case type
	keeps of it, whether it is ready, a reason, and what archiving means.

	THE SAME ACT AS THE LIFECYCLE DIALOG. It posts the archive act the
	Lifecycle dialog posts, and the server checks the same role and refuses an
	open case the same way. The Lifecycle path keeps working beside it.

	THE CHECKLIST IS READ, NOT ASSUMED. Every item comes from
	GET /api/case/{caseId}/archive/readiness, which reads it off the stored
	case; an item that could not be read says so. Only "Case ended" decides,
	because that is the one thing the archive act refuses on; the others
	inform. A case type adds its own items in the action's `checks` prop
	(`{ id, label, field, value }`: met when the case's field holds the value).

	@spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Archive this case')"
		:eyebrow="eyebrow"
		:subtitle="t('dossiq', 'The case goes to the archive and is only read afterwards.')"
		width="form"
		:noClose="busy"
		data-testid="case-archive-dialog"
		@closing="$emit('close')">
		<div v-if="!done" class="case-archive">
			<div v-if="kase" class="case-archive__case">
				<FolderOutline :size="20" class="case-archive__case-icon" />
				<span class="case-archive__case-text">
					<strong>{{ kase.title }}</strong>
					<span class="case-archive__muted">{{ [kase.identifier, kase.initiatorDisplayName].filter(Boolean).join(' · ') }}</span>
				</span>
				<span v-if="kase.resultPublicLabel" class="case-archive__badge">{{ kase.resultPublicLabel }}</span>
			</div>

			<section v-if="kase" class="case-archive__section">
				<h3 class="case-archive__title">
					{{ t('dossiq', 'Retention under the case type') }}
				</h3>
				<dl class="case-archive__facts">
					<div>
						<dt>{{ t('dossiq', 'Archive nomination') }}</dt>
						<dd>{{ kase.archiveNomination || t('dossiq', 'Not set') }}</dd>
					</div>
					<div>
						<dt>{{ t('dossiq', 'Destruction date') }}</dt>
						<dd>{{ kase.archiveActionDate || t('dossiq', 'Not set') }}</dd>
					</div>
				</dl>
			</section>

			<section class="case-archive__section" data-testid="case-archive-checklist">
				<h3 class="case-archive__title">
					{{ t('dossiq', 'Ready to archive') }}
				</h3>
				<p v-if="readinessError" class="case-archive__muted" role="status">
					{{ readinessError }}
				</p>
				<ul class="case-archive__checks">
					<li
						v-for="item in checklist"
						:key="item.id"
						class="case-archive__check"
						:data-testid="'case-archive-check-' + item.id">
						<CheckCircle v-if="item.met === true" :size="20" class="case-archive__check-icon case-archive__check-icon--met" />
						<HelpCircleOutline v-else-if="item.met === null" :size="20" class="case-archive__check-icon" />
						<CircleOutline v-else :size="20" class="case-archive__check-icon" />
						<strong class="case-archive__check-label">{{ item.label }}</strong>
						<span class="case-archive__muted">{{ item.detail }}</span>
					</li>
				</ul>
			</section>

			<div class="case-archive__field">
				<label class="case-archive__label" for="case-archive-reason">{{ t('dossiq', 'Reason') }}</label>
				<NcTextArea
					id="case-archive-reason"
					v-model="reason"
					:label="t('dossiq', 'Reason')"
					:labelOutside="true"
					resize="vertical"
					data-testid="case-archive-reason" />
				<span class="case-archive__muted">{{ t('dossiq', 'Goes into the history of the case.') }}</span>
			</div>

			<NcNoteCard type="info" :heading="t('dossiq', 'Read only afterwards')">
				{{ t('dossiq', 'Once archived, the documents, tasks and messages of this case can no longer be changed. The case stays findable until its archive action date.') }}
			</NcNoteCard>

			<p v-if="refusal" class="case-archive__error" role="alert" data-testid="case-archive-refusal">
				{{ refusal }}
			</p>
		</div>

		<p v-else role="status" data-testid="case-archive-done">
			{{ t('dossiq', 'The case is archived.') }}
		</p>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ done ? t('dossiq', 'Close') : t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!done"
				variant="primary"
				data-testid="case-archive-confirm"
				:disabled="!canArchive"
				@click="archive">
				<template #icon>
					<ArchiveOutline :size="20" />
				</template>
				{{ t('dossiq', 'Archive it') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'

const PAGE_REFRESH = 'cn:page:refresh'

/**
 * One server checklist item as a row: its label and the fact behind it.
 *
 * @param {object} item The item: `{ id, met, count?, total?, detail? }`.
 * @return {{id: string, met: (boolean|null), label: string, detail: string}} The row.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
export function checklistRow(item) {
	const met = item.met === null || item.met === undefined ? null : item.met === true
	const unknown = t('dossiq', 'Could not be read')
	switch (item.id) {
	case 'ended':
		return { id: item.id, met, label: t('dossiq', 'Case ended'), detail: met ? '' : t('dossiq', 'Finish or abort the case first') }
	case 'result':
		return { id: item.id, met, label: t('dossiq', 'Result recorded'), detail: String(item.detail || '') }
	case 'decision':
		return { id: item.id, met, label: t('dossiq', 'Decision made'), detail: met ? '' : t('dossiq', 'No decision on this case') }
	case 'documents':
		return { id: item.id, met, label: t('dossiq', 'Documents final'), detail: met === null ? unknown : t('dossiq', '{count} of {total} final', { count: item.count ?? 0, total: item.total ?? 0 }) }
	case 'openTasks':
		return { id: item.id, met, label: t('dossiq', 'Open tasks: {count}', { count: item.count ?? '?' }), detail: met === null ? unknown : (met ? t('dossiq', 'No task open anymore') : '') }
	default:
		return { id: String(item.id), met, label: String(item.label || item.id), detail: String(item.detail || '') }
	}
}

export default {
	name: 'CaseArchiveDialog',

	components: {
		ArchiveOutline,
		CheckCircle,
		CircleOutline,
		CnDialog,
		FolderOutline,
		HelpCircleOutline,
		NcButton,
		NcNoteCard,
		NcTextArea,
	},

	props: {
		/** The case; may arrive as the unresolved `@objectId` token, then the route answers. */
		caseId: {
			type: String,
			default: '',
		},

		/** The case type's own items: `{ id, label, field, value }`, met when the case's field holds the value. */
		checks: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close'],

	data() {
		return {
			kase: null,
			items: [],
			readinessError: '',
			allowed: false,
			refusalOfRole: '',
			reason: '',
			busy: false,
			refusal: '',
			done: false,
		}
	},

	computed: {
		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		targetCaseId() {
			const given = String(this.caseId || '')
			return given !== '' && !given.startsWith('@') ? given : String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		eyebrow() {
			return caseEyebrow(t('dossiq', 'Close off'), this.kase)
		},

		/**
		 * The server's items, then the case type's own.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		checklist() {
			const own = (this.checks || []).map((check) => ({
				id: String(check.id),
				met: this.kase ? String(this.kase[check.field] ?? '') === String(check.value) : null,
				label: t('dossiq', String(check.label || check.id)),
				detail: '',
			}))
			return [...this.items.map(checklistRow), ...own]
		},

		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		ended() {
			return this.items.some((item) => item.id === 'ended' && item.met === true)
		},

		/** @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md */
		canArchive() {
			return this.busy === false && this.allowed && this.ended && this.reason.trim() !== ''
		},
	},

	async mounted() {
		await Promise.all([this.loadReadiness(), this.loadPermission()])
		this.kase = await fetchCaseSummary(this.targetCaseId)
	},

	methods: {
		t,

		/**
		 * @param {string} path The route under /apps/dossiq/api/case/{caseId}.
		 * @return {string} The url.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		urlFor(path) {
			return generateUrl(`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/${path}`)
		},

		/**
		 * Read the checklist off the case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		async loadReadiness() {
			try {
				const { data } = await axios.get(this.urlFor('archive/readiness'))
				this.items = Array.isArray(data?.checklist) ? data.checklist : []
			} catch {
				this.items = []
				this.readinessError = t('dossiq', 'Whether this case is ready could not be read.')
			}
		},

		/**
		 * Ask whether this reader may archive, and the sentence when not.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		async loadPermission() {
			try {
				const { data } = await axios.get(this.urlFor('acts'))
				const verdict = (data?.acts || []).find((act) => act.act === 'archive')
				this.allowed = verdict?.allowed === true
				this.refusal = this.allowed ? '' : String(verdict?.reason || '')
			} catch {
				this.allowed = false
			}
		},

		/**
		 * Post the archive act once and show what the server answered.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		async archive() {
			if (!this.canArchive) {
				return
			}
			this.busy = true
			this.refusal = ''
			try {
				await axios.post(this.urlFor('archive'), { reason: this.reason.trim() })
				this.done = true
				emit(PAGE_REFRESH, {})
			} catch (failure) {
				const data = failure?.response?.data
				this.refusal = String(data?.message || data?.error || t('dossiq', 'The case could not be archived.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.case-archive {
	display: flex;
	flex-direction: column;
	gap: 18px;

	&__case {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 12px 14px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__case-text {
		display: flex;
		flex: 1 1 auto;
		flex-direction: column;
		min-width: 0;
	}

	&__badge {
		padding: 3px 10px;
		border-radius: 12px;
		background: var(--color-success-hover, var(--color-background-hover));
		color: var(--color-success-text);
		font-size: 13px;
		font-weight: 600;
	}

	&__section {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__title {
		margin: 0;
		font-size: 15px;
		font-weight: 600;
	}

	&__facts {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 12px 24px;
		margin: 0;

		/* Core's apps.css draws every dt/dd inline-block with a fixed, end-aligned dt. */
		dt,
		dd {
			display: block;
			width: auto;
			padding: 0;
			margin: 0;
			text-align: start;
		}

		dt {
			font-size: 13px;
			color: var(--color-text-maxcontrast);
		}

		dd {
			font-weight: 600;
		}
	}

	&__checks {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__check {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 10px 0;
		border-top: 1px solid var(--color-border);
	}

	&__check-icon {
		flex: none;
		color: var(--color-text-maxcontrast);

		&--met {
			color: var(--color-success-text);
		}
	}

	&__check-label {
		flex: 1 1 auto;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__label {
		font-weight: 600;
	}

	&__muted {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}
}
</style>
