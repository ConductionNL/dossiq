<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Generic document compare (decision 182): an original beside the file that
  went out, for any case type that keeps such pairs. The host passes
  `filesUrl`: GET on it answers `{ original: {fileName, mimeType}, delivered:
  {...} }` and `<filesUrl>/<side>` answers the bytes. The Woo case type's
  delivered set is the first user (woo-delivered-set-is-a-record REQ-WDS-004).

  The split view is filinq's (`OCA.Filinq.mountCompare`, filinq
  anonymization-review-workbench REQ-DDARW-014), mounted into an element this
  dialog owns and unmounted when it closes.

  Without filinq the dialog says the compare view needs filinq and offers the
  two files as links. It never draws something that looks like a comparison.

  @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Compare with the original')"
		size="full"
		data-testid="document-compare-dialog"
		@closing="$emit('close')">
		<div class="document-compare">
			<NcLoadingIcon v-if="busy" :size="32" />

			<p
				v-if="error"
				class="document-compare__error"
				data-testid="document-compare-error"
				role="alert">
				{{ error }}
			</p>

			<NcNoteCard
				v-if="!busy && !error && !viewerFound"
				type="info"
				data-testid="document-compare-needs-filinq">
				{{
					t(
						'dossiq',
						'The compare view needs Filinq. Open the two files separately.',
					)
				}}
			</NcNoteCard>

			<ul
				v-if="!busy && !error && !viewerFound"
				class="document-compare__links"
				data-testid="document-compare-links">
				<li v-for="side in sides" :key="side.key">
					<a :href="side.url" download
						>{{ side.label }}: {{ side.fileName }}</a
					>
				</li>
			</ul>

			<div
				v-show="viewerFound"
				ref="compare"
				class="document-compare__view"
				data-testid="document-compare-view" />
		</div>

		<template #actions>
			<NcButton data-testid="document-compare-close" @click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { loadFilinqCompare } from '../utils/filinqCompare.js'

export default {
	name: 'DocumentCompareDialog',

	components: { NcButton, NcDialog, NcLoadingIcon, NcNoteCard },

	props: {
		/**
		 * Where the pair is described; `<filesUrl>/original` and
		 * `<filesUrl>/delivered` answer the bytes.
		 */
		filesUrl: {
			type: String,
			required: true,
		},

		/** Optional `{ original, delivered }` pane titles. */
		labels: {
			type: Object,
			default: () => ({}),
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
		 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
		 */
		sides() {
			const labels = {
				original: this.labels.original || t('dossiq', 'Original'),
				delivered: this.labels.delivered || t('dossiq', 'Delivered'),
			}
			return ['original', 'delivered'].map((key) => ({
				key,
				label: labels[key],
				fileName: this.files?.[key]?.fileName || '',
				mimeType: this.files?.[key]?.mimeType || '',
				url: `${this.filesUrl}/${key}`,
			}))
		},
	},

	/**
	 * Read the item, then mount filinq's view when it is there.
	 *
	 * @return {Promise<void>} Nothing.
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
	 */
	async mounted() {
		try {
			const { data } = await axios.get(this.filesUrl)
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
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
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
.document-compare {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 60vh;
}

.document-compare__view {
	flex: 1;
	min-height: 60vh;
}

.document-compare__links {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.document-compare__links a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.document-compare__error {
	color: var(--color-error-text);
}
</style>
