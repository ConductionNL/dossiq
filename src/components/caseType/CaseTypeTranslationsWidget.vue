<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  A case type's labels in every language the register serves.

  WHY THIS EXISTS AT ALL. dossiq ships in 38 languages and its case types
  spoke one. The title, the purpose and the subject an administrator writes
  are register content, and decision C05 sends register content to
  OpenRegister's translation engine. The engine was built and never asked:
  until 2026-09-18 the register spelled the mark `x-translatable` and
  OpenRegister reads `translatable`. The mark is fixed, and this is the page
  that lets somebody use it.

  WHY dossiq DRAWS THIS AND DOES NOT EMBED SOMETHING. The proposal said "the
  language tabs OpenRegister already renders for a translatable property".
  There are none. `@conduction/nextcloud-vue` 3.4.0 contains no reference to
  `translatable`, `languageMeta` or `sourceLanguage`, so the library that
  builds every form on this page has never heard of the mark, and
  OpenRegister renders nothing into a leaf app's page. The mechanism is
  OpenRegister's and stays there; the surface is this widget, which is 80
  lines of reading and one PATCH.

  WHAT IT REFUSES TO DO. It does not translate anything. OpenRegister has
  `BulkTranslationService` for that and a button here would be a second way
  to start it, in the one app that does not own it.

  @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
-->
<template>
	<div class="case-type-translations" data-testid="case-type-translations">
		<NcLoadingIcon v-if="loading" :size="24" />

		<p v-else-if="error" class="case-type-translations__empty">
			{{
				t(
					'dossiq',
					'The translations could not be read. Reload the page to try again.',
				)
			}}
		</p>

		<p
			v-else-if="properties.length === 0"
			class="case-type-translations__empty"
			data-testid="case-type-translations-none">
			{{ t('dossiq', 'This case type has no labels that can be translated.') }}
		</p>

		<template v-else>
			<ul
				class="case-type-translations__chips"
				data-testid="case-type-translation-chips">
				<li
					v-for="chip in chips"
					:key="chip.language"
					class="case-type-translations__chip"
					:class="{
						'case-type-translations__chip--complete': chip.complete,
					}"
					:data-language="chip.language">
					<span class="case-type-translations__chip-name">{{
						chip.name
					}}</span>
					<span class="case-type-translations__chip-count">{{
						chip.count
					}}</span>
				</li>
			</ul>

			<p class="case-type-translations__source">
				{{ sourceNote }}
			</p>

			<div class="case-type-translations__tabs" role="tablist">
				<NcButton
					v-for="language in languages"
					:key="language"
					:variant="language === activeLanguage ? 'primary' : 'tertiary'"
					role="tab"
					:aria-selected="String(language === activeLanguage)"
					:data-language="language"
					@click="activeLanguage = language">
					{{ nameOf(language) }}
				</NcButton>
			</div>

			<div
				v-for="property in properties"
				:key="property"
				class="case-type-translations__field"
				:data-property="property">
				<NcTextField
					:label="labelOf(property)"
					:modelValue="draftOf(property)"
					:disabled="activeLanguage === sourceLanguage"
					@update:value="setDraft(property, $event)" />

				<span
					v-if="isStale(property)"
					class="case-type-translations__stale"
					:data-property="property">
					{{ t('dossiq', 'Out of date') }}
				</span>
			</div>

			<NcButton
				variant="primary"
				:disabled="saving || !hasEdits"
				data-testid="case-type-translations-save"
				@click="save">
				{{ t('dossiq', 'Save') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { getLanguage, translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	caseTypeProperties,
	caseTypeWithTranslations,
	completenessOf,
	registerLanguages,
	saveLabel,
	STATUS_OUTDATED,
	translationRows,
} from '../../services/caseTypeTranslationApi.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseTypeTranslationsWidget',

	components: { NcButton, NcLoadingIcon, NcTextField },

	data() {
		return {
			loading: true,
			error: false,
			saving: false,
			languages: [],
			sourceLanguage: 'nl',
			properties: [],
			titles: {},
			values: {},
			statuses: {},
			drafts: {},
			activeLanguage: '',
		}
	},

	computed: {
		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * One chip per declared language, counting a stale label as missing.
		 *
		 * The languages come from the REGISTER and not from the values that
		 * happen to be there. A language nobody has started reads "0 of 5",
		 * which is the one thing a completeness chip is for; derived from the
		 * data it would not appear at all.
		 *
		 * @return {Array<object>} The chips, source language first.
		 */
		chips() {
			return this.languages.map((language) => {
				const { translated, total } = completenessOf({
					properties: this.properties,
					values: this.values,
					statuses: this.statuses,
					language,
					sourceLanguage: this.sourceLanguage,
				})

				return {
					language,
					name: this.nameOf(language),
					// TRANSLATORS: how many of a case type's labels exist in one language
					count: t('dossiq', '{translated} of {total}', {
						translated,
						total,
					}),
					complete: translated === total,
				}
			})
		},

		/**
		 * What the source language means for the reader.
		 *
		 * @return {string} The one line under the chips.
		 */
		sourceNote() {
			return t(
				'dossiq',
				'{language} is the source. Change a {language} label and its translations are marked out of date.',
				{ language: this.nameOf(this.sourceLanguage) },
			)
		},

		/**
		 * Whether anything is waiting to be saved.
		 *
		 * @return {boolean} True when a draft differs from what is stored.
		 */
		hasEdits() {
			return this.properties.some(
				(property) => this.draftOf(property) !== this.storedOf(property),
			)
		},
	},

	/**
	 * Read the case type once the widget is on the page.
	 *
	 * @return {Promise<void>}
	 */
	async mounted() {
		subscribe(PAGE_REFRESH, this.load)
		await this.load()
	},

	beforeUnmount() {
		unsubscribe(PAGE_REFRESH, this.load)
	},

	methods: {
		t,

		/**
		 * A language's name in the reader's own language.
		 *
		 * `Intl.DisplayNames` keeps 38 language names out of this app's
		 * catalogue, where they would each need translating 38 times.
		 *
		 * @param {string} code The BCP 47 tag.
		 * @return {string} The display name, or the tag when the browser has none.
		 */
		nameOf(code) {
			try {
				return (
					new Intl.DisplayNames([getLanguage()], { type: 'language' }).of(
						code,
					) ?? code
				)
			} catch {
				return code
			}
		},

		/**
		 * A label's own title, as the schema names it.
		 *
		 * @param {string} property The property name.
		 * @return {string} The title, falling back to the property name.
		 */
		labelOf(property) {
			return this.titles[property] ?? property
		},

		/**
		 * What is stored for a property in the language being edited.
		 *
		 * @param {string} property The property name.
		 * @return {string} The stored value, or an empty string.
		 */
		storedOf(property) {
			return this.values?.[property]?.[this.activeLanguage] ?? ''
		},

		/**
		 * What the reader has typed, falling back to what is stored.
		 *
		 * @param {string} property The property name.
		 * @return {string} The draft value.
		 */
		draftOf(property) {
			const typed = this.drafts?.[this.activeLanguage]?.[property]
			return typed === undefined ? this.storedOf(property) : typed
		},

		/**
		 * Record an edit against the language it was made in.
		 *
		 * @param {string} property The property name.
		 * @param {string} value The new value.
		 * @return {void}
		 */
		setDraft(property, value) {
			const language = this.activeLanguage
			this.drafts = {
				...this.drafts,
				[language]: { ...(this.drafts[language] ?? {}), [property]: value },
			}
		},

		/**
		 * Whether the stored value for this property moved out from under it.
		 *
		 * @param {string} property The property name.
		 * @return {boolean} True when OpenRegister marked the row outdated.
		 */
		isStale(property) {
			return (
				(this.statuses?.[property]?.[this.activeLanguage] ?? '')
				=== STATUS_OUTDATED
			)
		},

		/**
		 * Read the register, the case type and the sidecar.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			if (!this.caseTypeId) {
				this.loading = false
				this.error = true
				return
			}

			this.loading = true
			try {
				const [languages, read, definitions] = await Promise.all([
					registerLanguages(),
					caseTypeWithTranslations(this.caseTypeId),
					caseTypeProperties(),
				])

				const meta = read.languageMeta ?? {}
				this.properties = Object.keys(meta)
				this.sourceLanguage =
					meta[this.properties[0]]?.sourceLanguage ?? languages[0] ?? 'nl'
				this.languages =
					languages.length > 0 ? languages : [this.sourceLanguage]
				this.activeLanguage =
					this.languages.find((code) => code !== this.sourceLanguage)
					?? this.sourceLanguage

				this.values = Object.fromEntries(
					this.properties.map((property) => {
						const stored = read.object?.[property]
						const map =
							stored !== null && typeof stored === 'object'
								? stored
								: {}
						return [property, { ...map }]
					}),
				)
				this.titles = Object.fromEntries(
					this.properties.map((property) => [
						property,
						definitions[property]?.title ?? property,
					]),
				)

				const uuid = String(
					read.object?.['@self']?.uuid
						?? read.object?.id
						?? this.caseTypeId,
				)
				this.statuses = {}
				for (const row of await translationRows(uuid)) {
					const property = String(row.property ?? '')
					const language = String(row.language ?? '')
					if (property === '' || language === '') {
						continue
					}
					this.statuses[property] = {
						...(this.statuses[property] ?? {}),
						[language]: row.status,
					}
				}

				this.drafts = {}
				this.error = false
			} catch {
				// An outage and a case type with nothing translated look the
				// same from here, and only one of them is somebody's problem.
				this.error = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Write every edited label back, each as a whole language map.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			try {
				for (const property of this.properties) {
					const draft = this.draftOf(property)
					if (draft === this.storedOf(property)) {
						continue
					}

					const map = {
						...(this.values[property] ?? {}),
						[this.activeLanguage]: draft,
					}
					await saveLabel(this.caseTypeId, property, map)
					this.values = { ...this.values, [property]: map }
				}

				this.drafts = { ...this.drafts, [this.activeLanguage]: {} }
				showSuccess(t('dossiq', 'The labels are saved.'))
			} catch {
				showError(t('dossiq', 'The labels could not be saved.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.case-type-translations__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	list-style: none;
	margin: 0 0 8px;
	padding: 0;
}

.case-type-translations__chip {
	align-items: center;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
	display: flex;
	gap: 6px;
	padding: 2px 10px;
}

.case-type-translations__chip--complete {
	background: var(--color-success, var(--color-background-dark));
	color: var(--color-primary-text);
}

.case-type-translations__chip-count {
	opacity: 0.8;
}

.case-type-translations__source,
.case-type-translations__empty {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
}

.case-type-translations__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 8px;
}

.case-type-translations__field {
	align-items: center;
	display: flex;
	gap: 8px;
	margin-bottom: 8px;
}

.case-type-translations__stale {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
	white-space: nowrap;
}
</style>
