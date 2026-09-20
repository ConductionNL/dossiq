<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  CaseWorkInstructionPanel — the work instruction for THIS KIND of case, on
  the case itself.

  knowledge-base-on-the-case REQ-CKB-01, parity row 11.26. A handler who meets
  an unusual bezwaar has nowhere to read how this municipality handles it: the
  instruction is a Word file on a share, or in the head of the colleague who is
  on holiday. Collectives is the wiki, `caseType.knowledgeBasePage` names the
  page, and this is the one thing that puts the two together WITHOUT anybody
  linking a page per case. The case's own linked pages are the section beside
  this one, through OpenRegister's `collectives` leaf.

  🔴 WHY A COMPONENT AND NOT A DECLARED FIELD. The value lives on the case
  TYPE, and the page is bound to the CASE. `CnDetailPage` reads no `extend`
  (measured against the installed @conduction/nextcloud-vue 3.4.0: the string
  `extend` does not occur in CnDetailPage.vue), so a dotted path over the
  case's `caseType` reference resolves to nothing and a widget declared that
  way renders blank while looking configured. That is the exact failure this
  whole change was written against, so the reference is followed here, once,
  in code that says what it is doing.

  🔴 THE PAGE IS A REFERENCE AND NEVER ITS TEXT. dossiq fetches no article
  body and stores none. Who may read the page is the collective's own
  business: a collective is scoped to a Nextcloud team, and a handler whose
  role group is not in that team meets Collectives' own refusal on the page
  itself rather than reading a copy dossiq made. So there is nothing here to
  enforce and nothing here to leak.

  NOTHING RENDERS WHEN NO PAGE IS SET, on purpose. Most case types will not
  have one for a while, and a heading over "no work instruction" is a reproach
  to the reader for something an administrator has not done yet.
  `CaseSectionsWidget` drops the heading of a section whose widget drew
  nothing, so silence here costs no empty block.

  A LOOKUP THAT FAILED IS NOT A CASE TYPE WITHOUT AN INSTRUCTION. The two look
  identical from a blank panel, so a failed read says so in a line and offers
  to try again.
-->
<template>
	<div v-if="showsSomething" class="case-work-instruction">
		<div v-if="failed" class="case-work-instruction__error">
			<AlertCircleOutline :size="20" />
			<span>{{ errorText }}</span>
			<NcButton variant="tertiary" @click="load">
				{{ retryLabel }}
			</NcButton>
		</div>
		<a
			v-else
			:href="pageHref"
			class="case-work-instruction__link"
			target="_blank"
			rel="noopener noreferrer"
			data-testid="case-work-instruction-link">
			<BookOpenPageVariant :size="20" />
			<span>{{ linkLabel }}</span>
		</a>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'

/**
 * The schemes a work instruction may be opened with.
 *
 * 🔴 A WHITELIST AND NOT A BLACKLIST. `knowledgeBasePage` is free text an
 * administrator types, it carries no `format` on purpose (adding one to a
 * property OpenRegister already stores is breaking for every row that does not
 * match it), and it is rendered as an `href`. A `javascript:` value there would
 * run on a page an administrator does not own, so anything that is not one of
 * these two is treated as a Collectives page path instead of as a URL.
 *
 * @type {string[]}
 */
const ALLOWED_SCHEMES = ['http:', 'https:']

/**
 * CaseWorkInstructionPanel — link the case type's Collectives page.
 */
export default {
	name: 'CaseWorkInstructionPanel',

	components: { AlertCircleOutline, BookOpenPageVariant, NcButton },

	props: {
		/** The case object this panel is rendered on. */
		objectData: { type: Object, default: null },
	},

	data() {
		return {
			/** The resolved page, empty until a lookup answers one. */
			page: '',
			/** True when the last lookup could not be made. */
			failed: false,
		}
	},

	computed: {
		/**
		 * The case type's uuid, read off the case.
		 *
		 * `caseType` is a reference, and OpenRegister answers it either as the
		 * bare uuid or as the inlined object depending on what the page asked
		 * for. Both shapes are read rather than assumed, the same rule
		 * `caseIdOfDispatch` follows for a dispatch's case.
		 *
		 * @return {string} The uuid, or the empty string.
		 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
		 */
		caseTypeId() {
			const value = this.objectData ? this.objectData.caseType : null
			if (typeof value === 'string') {
				return value
			}

			if (value !== null && typeof value === 'object') {
				return String(value.id || value['@self']?.id || '')
			}

			return ''
		},

		/**
		 * The instruction already inlined on the case, when the case type came
		 * back as an object.
		 *
		 * Saves the round trip on a page that expanded the reference, and is
		 * the only reason this panel can render on its first paint.
		 *
		 * @return {string} The page, or the empty string.
		 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
		 */
		inlinedPage() {
			const value = this.objectData ? this.objectData.caseType : null
			if (value === null || typeof value !== 'object') {
				return ''
			}

			return typeof value.knowledgeBasePage === 'string'
				? value.knowledgeBasePage.trim()
				: ''
		},

		/**
		 * Where the instruction opens.
		 *
		 * An absolute `http`/`https` value is the page's own URL and is opened
		 * as it stands. Anything else is read as a Collectives page path,
		 * because that is what a municipality pastes when it copies a page out
		 * of the app's own breadcrumb, and because it is the only reading that
		 * cannot produce a scheme nobody asked for.
		 *
		 * @return {string} The href, or the empty string when nothing resolves.
		 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
		 */
		pageHref() {
			const value = this.page.trim()
			if (value === '') {
				return ''
			}

			try {
				const parsed = new URL(value)
				if (ALLOWED_SCHEMES.includes(parsed.protocol) === true) {
					return parsed.href
				}

				// A parsable value with a scheme we do not open: NOT a path
				// either, because `javascript:alert(1)` would otherwise be
				// pasted onto the end of the Collectives route and opened.
				return ''
			} catch {
				// Not a URL, so a page path. Leading slashes are stripped so a
				// pasted `/Handleidingen/Bezwaar` does not become a double
				// slash the router reads as a host.
				return generateUrl('/apps/collectives/' + value.replace(/^\/+/, ''))
			}
		},

		/**
		 * Whether this panel draws anything at all.
		 *
		 * @return {boolean} True for a resolved page or a failed lookup.
		 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
		 */
		showsSomething() {
			return this.failed === true || this.pageHref !== ''
		},

		/**
		 * The link's wording.
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
		 */
		linkLabel() {
			return t('dossiq', 'Read how we handle this kind of case')
		},

		/**
		 * What a failed lookup says.
		 *
		 * @return {string} The line.
		 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
		 */
		errorText() {
			return t(
				'dossiq',
				'The work instruction for this case type could not be looked up.',
			)
		},

		/**
		 * The retry wording.
		 *
		 * @return {string} The label.
		 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
		 */
		retryLabel() {
			return t('dossiq', 'Try again')
		},
	},

	watch: {
		caseTypeId: {
			immediate: true,
			/**
			 * Look the instruction up again whenever the case type changes.
			 *
			 * @return {void}
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Resolve the case type's `knowledgeBasePage`.
		 *
		 * @return {Promise<void>} Resolves once the panel has its answer.
		 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
		 */
		async load() {
			this.failed = false

			if (this.inlinedPage !== '') {
				this.page = this.inlinedPage

				return
			}

			this.page = ''
			if (this.caseTypeId === '') {
				// A case with no type has no instruction to show, and that is
				// not a failure: it is a case somebody has not typed yet.
				return
			}

			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/dossiq/caseType/{id}',
						{ id: this.caseTypeId },
					),
				)
				const body = response?.data ?? {}
				const value = body.knowledgeBasePage

				this.page = typeof value === 'string' ? value.trim() : ''
			} catch {
				this.failed = true
			}
		},
	},
}
</script>

<style scoped>
.case-work-instruction__link {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
	padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
	text-decoration: underline;
}

.case-work-instruction__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
	color: var(--color-error-text, var(--color-error));
}
</style>
