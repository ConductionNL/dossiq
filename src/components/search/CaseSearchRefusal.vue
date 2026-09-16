<template>
	<NcNoteCard
		v-if="refusal"
		type="warning"
		class="case-search-refusal"
		data-testid="case-search-refusal">
		<p class="case-search-refusal__headline" data-testid="case-search-refusal-headline">
			{{ headline }}
		</p>
		<p class="case-search-refusal__term" data-testid="case-search-refusal-term">
			<span>{{ refusal.before }}</span>
			<mark v-if="refusal.at">{{ refusal.at }}</mark>
			<span>{{ refusal.after }}</span>
		</p>
		<p class="case-search-refusal__reason" data-testid="case-search-refusal-reason">
			{{ refusal.message }}
		</p>
		<p class="case-search-refusal__next">
			{{ nextStep }}
		</p>
	</NcNoteCard>
</template>

<script>
/**
 * What a refused search looks like on the Cases page.
 *
 * 🔴 WITHOUT THIS THE PAGE SAYS NOTHING MATCHED. OpenRegister refuses a term
 * it cannot parse with a 400 so it is never run as a literal string, because a
 * literal returns zero rows and reads as an honest empty result.
 * `useObjectStore.fetchCollection()` records the refusal on
 * `objectStore.errors['dossiq-case']` and returns `[]` anyway, and CnIndexPage
 * renders its empty state over it. A reader gets "no cases match" for a
 * bracket they forgot to close, and tries a different word.
 *
 * It mounts through `pages[].slots` rather than living in the library, because
 * the search box is CnIndexPage's and dossiq does not own it. The slot name is
 * `below-header`: `after-search` sits inside the actions bar and holds inline
 * refinement controls, which is the wrong shape for three lines of prose.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */

import { useObjectStore } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import {
	readSearchRefusal,
	searchRefusalHeadline,
} from '../../utils/searchRefusal.js'

/** The object store key CnIndexPage self-fetch mode uses for the Cases page. */
const CASE_TYPE_KEY = 'dossiq-case'

export default {
	name: 'CaseSearchRefusal',

	components: { NcNoteCard },

	computed: {
		/**
		 * The shared object store, where the list's own failure is recorded.
		 *
		 * @return {object} The store.
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * The term the reader typed, which the list keeps in the address bar.
		 *
		 * @return {string} The term, or an empty string.
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
		 */
		term() {
			const fromRoute = this.$route?.query?._search

			return typeof fromRoute === 'string' ? fromRoute : ''
		},

		/**
		 * The refusal to show, or null when the last fetch was not refused.
		 *
		 * @return {object|null} The refusal.
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
		 */
		refusal() {
			return readSearchRefusal({
				error: this.objectStore?.errors?.[CASE_TYPE_KEY] ?? null,
				term: this.term,
			})
		},

		/**
		 * The sentence above the term.
		 *
		 * @return {string} The headline.
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
		 */
		headline() {
			return searchRefusalHeadline(this.refusal, t)
		},

		/**
		 * What the reader does next.
		 *
		 * @return {string} The instruction.
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
		 */
		nextStep() {
			return t(
				'dossiq',
				'Correct that character, or search for plain words. AND, OR and NOT work in capitals, and quotes make a phrase.',
			)
		},
	},
}
</script>

<style scoped>
.case-search-refusal {
	margin-block-end: 8px;
}

.case-search-refusal__headline {
	font-weight: bold;
}

.case-search-refusal__term {
	font-family: var(--font-face-monospace, monospace);
	overflow-wrap: anywhere;
	white-space: pre-wrap;
}

.case-search-refusal__term mark {
	background-color: var(--color-warning);
	color: var(--color-main-text);
	font-weight: bold;
}

.case-search-refusal__reason,
.case-search-refusal__next {
	color: var(--color-text-maxcontrast);
}
</style>
