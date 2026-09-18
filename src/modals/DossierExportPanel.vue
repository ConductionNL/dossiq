<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The ordered dossier a griffier submits to the bestuursrechter, shown
	before it is submitted.

	`GET /api/dossier/{caseId}/export` has answered since the bezwaar-beroep
	work shipped, and no line in `src/` has ever called it. It returns the
	plan `BeroepDossierExport::buildPlan()` builds: every document of the
	bezwaar and beroep chain, in Awb order, renamed `01-primair-besluit.pdf`
	and so on. A plan nobody can see is a plan nobody can check, and the
	order is the part a court reads first.

	This panel is read-only on purpose. It renames nothing and writes
	nothing; it shows what the export WOULD contain, so the mistake is found
	before the submission rather than after it.

	Spec: openspec/specs/bezwaar-beroep-workflow/spec.md
-->
<template>
	<NcModal v-if="open" size="normal" @close="$emit('close')">
		<div class="dossier-export-panel">
			<h4 class="dossier-export-panel__title">
				{{ t('dossiq', 'Dossier for the court') }}
			</h4>

			<NcLoadingIcon v-if="loading" :size="24" />

			<NcEmptyContent v-else-if="error !== ''" :name="error">
				<template #icon>
					<AlertCircleOutline :size="20" />
				</template>
			</NcEmptyContent>

			<NcEmptyContent
				v-else-if="entries.length === 0"
				:name="t('dossiq', 'This case has no documents to submit')">
				<template #icon>
					<FileDocumentOutline :size="20" />
				</template>
			</NcEmptyContent>

			<template v-else>
				<p class="dossier-export-panel__count">
					{{
						n(
							'dossiq',
							'%n document, in the order it is submitted',
							'%n documents, in the order they are submitted',
							entries.length,
						)
					}}
				</p>
				<ol class="dossier-export-panel__list">
					<li
						v-for="entry in entries"
						:key="entry.sequence"
						class="dossier-export-panel__item">
						<span class="dossier-export-panel__filename">{{
							entry.filename
						}}</span>
						<span class="dossier-export-panel__meta">{{
							entry.title
						}}</span>
					</li>
				</ol>
			</template>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'

/**
 * Read-only view of the Awb-ordered dossier export plan for one case.
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */
export default {
	name: 'DossierExportPanel',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
		AlertCircleOutline,
		FileDocumentOutline,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		/** May arrive as the unresolved `@objectId` token; see resolvedCaseId. */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],
	data() {
		return {
			entries: [],
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The case whose plan is being read.
		 *
		 * @return {string} The case id, or an empty string.
		 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
		 */
		resolvedCaseId() {
			const fromProp = this.caseId || ''
			if (fromProp !== '' && !fromProp.startsWith('@')) {
				return fromProp
			}
			return (this.$route && this.$route.params && this.$route.params.id) || ''
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Read the plan the moment the panel opens.
			 *
			 * @param {boolean} isOpen Whether the modal is showing.
			 * @return {Promise<void>}
			 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
			 */
			async handler(isOpen) {
				if (isOpen) {
					await this.fetchPlan()
				}
			},
		},
	},

	methods: {
		/**
		 * Read the export plan for the current case.
		 *
		 * 🔴 AN EMPTY LIST AND A REFUSED READ ARE DIFFERENT ANSWERS. The
		 * endpoint answers 403 when the reader may not read the chain, and 200
		 * with `entries: []` when the case genuinely holds no documents. Both
		 * render as nothing on screen unless they are told apart here, and a
		 * griffier who reads "no documents" over a permission refusal submits
		 * an empty dossier to a court.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
		 */
		async fetchPlan() {
			this.entries = []
			this.error = ''
			if (this.resolvedCaseId === '') {
				this.error = this.t('dossiq', 'No case to export')
				return
			}
			this.loading = true
			try {
				const url = generateUrl(
					`/apps/dossiq/api/dossier/${encodeURIComponent(this.resolvedCaseId)}/export`,
				)
				const { data } = await axios.get(url)
				this.entries = Array.isArray(data?.entries) ? data.entries : []
			} catch (e) {
				const status = e?.response?.status
				this.error =
					status === 403
						? this.t(
								'dossiq',
								'You may not read every case in this chain',
							)
						: this.t('dossiq', 'Could not build the dossier export')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.dossier-export-panel {
	padding: 12px;
}

.dossier-export-panel__title {
	margin: 0 0 8px;
}

.dossier-export-panel__count {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.dossier-export-panel__list {
	margin: 0;
	padding-inline-start: 0;
	list-style: none;
}

.dossier-export-panel__item {
	display: flex;
	flex-direction: column;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.dossier-export-panel__filename {
	font-weight: bold;
	word-break: break-all;
}

.dossier-export-panel__meta {
	color: var(--color-text-maxcontrast);
}
</style>
