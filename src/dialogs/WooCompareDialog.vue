<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  One item of a delivered Woo set: the original as it came in beside the file
  that went out (woo-delivered-set-is-a-record REQ-WDS-004).

  The split view is filinq's (`OCA.Filinq.mountCompare`, filinq
  anonymization-review-workbench REQ-DDARW-014), mounted into an element this
  dialog owns and unmounted when it closes. Both files are read from dossiq's
  own endpoints behind the case read guard, so the view shows the bytes the
  set's hashes are about.

  Without filinq the dialog says the compare view needs filinq and offers the
  two files as links. It never draws something that looks like a comparison.

  @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Compare with the original')"
		size="full"
		data-testid="woo-compare-dialog"
		@closing="$emit('close')">
		<div class="woo-compare">
			<NcLoadingIcon v-if="busy" :size="32" />

			<p
				v-if="error"
				class="woo-compare__error"
				data-testid="woo-compare-error"
				role="alert">
				{{ error }}
			</p>

			<NcNoteCard
				v-if="!busy && !error && !viewerFound"
				type="info"
				data-testid="woo-compare-needs-filinq">
				{{
					t(
						'dossiq',
						'The compare view needs Filinq. Open the two files separately.',
					)
				}}
			</NcNoteCard>

			<ul
				v-if="!busy && !error && !viewerFound"
				class="woo-compare__links"
				data-testid="woo-compare-links">
				<li v-for="side in sides" :key="side.key">
					<a :href="side.url" download
						>{{ side.label }}: {{ side.fileName }}</a
					>
				</li>
			</ul>

			<div
				v-show="viewerFound"
				ref="compare"
				class="woo-compare__view"
				data-testid="woo-compare-view" />
		</div>

		<template #actions>
			<NcButton data-testid="woo-compare-close" @click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { loadFilinqCompare } from '../utils/filinqCompare.js'

export default {
	name: 'WooCompareDialog',

	components: { NcButton, NcDialog, NcLoadingIcon, NcNoteCard },

	props: {
		/** The case the set was delivered from. */
		caseId: {
			type: String,
			required: true,
		},

		/** The delivered set. */
		setId: {
			type: String,
			required: true,
		},

		/** The item's position in the set's `items`. */
		index: {
			type: Number,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			busy: true,
			error: '',
			files: null,
			viewerFound: false,
			handle: null,
		}
	},

	computed: {
		/**
		 * Both sides, the original first, with the URL that answers its bytes.
		 *
		 * @return {Array<object>} `{ key, label, fileName, mimeType, url }`.
		 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
		 */
		sides() {
			const labels = {
				original: t('dossiq', 'Original'),
				delivered: t('dossiq', 'Delivered'),
			}
			return ['original', 'delivered'].map((key) => ({
				key,
				label: labels[key],
				fileName: this.files?.[key]?.fileName || '',
				mimeType: this.files?.[key]?.mimeType || '',
				url: generateUrl(
					'/apps/dossiq/api/cases/{caseId}/woo/delivered-sets/{setId}/items/{index}/{side}',
					{
						caseId: this.caseId,
						setId: this.setId,
						index: this.index,
						side: key,
					},
				),
			}))
		},
	},

	/**
	 * Read the item, then mount filinq's view when it is there.
	 *
	 * @return {Promise<void>} Nothing.
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
	 */
	async mounted() {
		try {
			const { data } = await axios.get(
				generateUrl(
					'/apps/dossiq/api/cases/{caseId}/woo/delivered-sets/{setId}/items/{index}',
					{
						caseId: this.caseId,
						setId: this.setId,
						index: this.index,
					},
				),
			)
			this.files = data
		} catch {
			this.error = t('dossiq', 'The files of this item could not be read.')
			this.busy = false
			return
		}

		const mountCompare = await loadFilinqCompare()
		this.busy = false
		if (typeof mountCompare !== 'function') {
			return
		}
		this.viewerFound = true
		await this.$nextTick()
		const [original, delivered] = this.sides
		try {
			this.handle = mountCompare(this.$refs.compare, {
				original: {
					fileName: original.fileName,
					mimeType: original.mimeType,
					url: original.url,
				},
				delivered: {
					fileName: delivered.fileName,
					mimeType: delivered.mimeType,
					url: delivered.url,
				},
				labels: { original: original.label, delivered: delivered.label },
			})
		} catch {
			// A filinq that refuses the call is no view: fall back to the links.
			this.viewerFound = false
		}
	},

	/**
	 * Remove filinq's view with the dialog.
	 *
	 * @return {void}
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
	 */
	beforeUnmount() {
		this.handle?.unmount()
		this.handle = null
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.woo-compare {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 60vh;
}

.woo-compare__view {
	flex: 1;
	min-height: 60vh;
}

.woo-compare__links {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.woo-compare__links a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.woo-compare__error {
	color: var(--color-error-text);
}
</style>
