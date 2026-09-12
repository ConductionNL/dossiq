<template>
	<div class="case-type-detail">
		<div class="case-type-detail__header">
			<NcButton variant="tertiary" @click="$emit('back')">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ t('dossiq', 'Back to list') }}
			</NcButton>

			<h3 class="case-type-detail__title">
				{{
					isCreate
						? t('dossiq', 'New Case Type')
						: form.title || t('dossiq', 'Case Type')
				}}
			</h3>

			<div class="case-type-detail__actions">
				<span v-if="!isCreate" class="case-type-detail__version">
					{{ t('dossiq', 'Version {version}', { version: version }) }}
				</span>
				<NcButton
					v-if="!isCreate && form.isDraft"
					variant="secondary"
					data-testid="case-type-publish"
					@click="publishDialogOpen = true">
					{{ t('dossiq', 'Publish') }}
				</NcButton>
				<NcButton
					v-if="!isCreate && !form.isDraft"
					variant="secondary"
					@click="unpublish">
					{{ t('dossiq', 'Unpublish') }}
				</NcButton>
				<NcButton
					v-if="!isCreate && !form.isDraft && isCurrentVersion"
					variant="secondary"
					:disabled="versioning"
					data-testid="case-type-new-version"
					@click="newVersion">
					<template v-if="versioning" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('dossiq', 'New version') }}
				</NcButton>
				<NcButton
					v-if="!isCreate"
					variant="secondary"
					:disabled="duplicating"
					@click="duplicate">
					<template v-if="duplicating" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('dossiq', 'Duplicate') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('dossiq', 'Save') }}
				</NcButton>
			</div>
		</div>

		<!-- Superseded version notice -->
		<div
			v-if="!isCreate && !isCurrentVersion"
			class="case-type-detail__warning"
			data-testid="case-type-superseded">
			<p>
				{{
					t(
						'dossiq',
						'A newer version has replaced this one. Cases already running on it carry on here. New cases go on the newer version.',
					)
				}}
			</p>
		</div>

		<!-- Running cases warning -->
		<div
			v-if="hasRunningCases && !isCreate && !form.isDraft && isCurrentVersion"
			class="case-type-detail__warning"
			data-testid="case-type-running-cases">
			<p>
				{{
					t(
						'dossiq',
						'Cases are running on this version. Editing it changes them too. Make a new version instead, and the running cases stay on this one.',
					)
				}}
			</p>
		</div>

		<!-- Save feedback -->
		<p v-if="saveError" class="case-type-detail__error">
			{{ saveError }}
		</p>
		<p v-if="saveSuccess" class="case-type-detail__success">
			{{ t('dossiq', 'Saved successfully') }}
		</p>

		<NcLoadingIcon v-if="loadingDetail" />

		<template v-else>
			<!-- Tabs -->
			<div class="case-type-detail__tabs">
				<button
					v-for="tab in tabs"
					:key="tab.id"
					class="case-type-detail__tab"
					:class="{
						'case-type-detail__tab--active': activeTab === tab.id,
					}"
					@click="activeTab = tab.id">
					{{ tab.label }}
				</button>
			</div>

			<!-- Tab content -->
			<div class="case-type-detail__tab-content">
				<GeneralTab
					v-if="activeTab === 'general'"
					:form="form"
					:errors="validationErrors"
					@update="onFieldUpdate" />
				<StatusesTab
					v-else-if="activeTab === 'statuses'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<ResultsTab
					v-else-if="activeTab === 'results'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<RolesTab
					v-else-if="activeTab === 'roles'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<PropertiesTab
					v-else-if="activeTab === 'properties'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<DocumentTypesTab
					v-else-if="activeTab === 'documents'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<DecisionTypesTab
					v-else-if="activeTab === 'decisions'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<SubCaseTypesTab
					v-else-if="activeTab === 'subCaseTypes'"
					:caseTypeId="caseTypeId" />
				<WorkflowTab
					v-else-if="activeTab === 'workflow'"
					:caseTypeId="caseTypeId" />
				<EmailTemplateAdmin
					v-else-if="activeTab === 'emailTemplates'"
					:caseTypeId="caseTypeId" />
			</div>
		</template>

		<CaseTypePublishDialog
			v-if="publishDialogOpen"
			:caseTypeId="caseTypeId"
			@close="onPublishDialogClosed" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import CaseTypePublishDialog from '../../dialogs/CaseTypePublishDialog.vue'
import EmailTemplateAdmin from '../casetypes/components/EmailTemplateAdmin.vue'
import DecisionTypesTab from './tabs/DecisionTypesTab.vue'
import DocumentTypesTab from './tabs/DocumentTypesTab.vue'
import GeneralTab from './tabs/GeneralTab.vue'
import PropertiesTab from './tabs/PropertiesTab.vue'
import ResultsTab from './tabs/ResultsTab.vue'
import RolesTab from './tabs/RolesTab.vue'
import StatusesTab from './tabs/StatusesTab.vue'
import SubCaseTypesTab from './tabs/SubCaseTypesTab.vue'
import WorkflowTab from './tabs/WorkflowTab.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { publishRefusalMessage } from '../../utils/caseTypePublish.js'
import { validateCaseType } from '../../utils/caseTypeValidation.js'
import { isCurrentCaseTypeVersion } from '../../utils/caseValidation.js'

const EMPTY_FORM = {
	title: '',
	description: '',
	identifier: '',
	purpose: '',
	trigger: '',
	subject: '',
	initiatorAction: '',
	handlerAction: '',
	origin: '',
	processingDeadline: '',
	serviceTarget: '',
	extensionAllowed: false,
	extensionPeriod: '',
	suspensionAllowed: false,
	confidentiality: '',
	iv3TaskField: '',
	publicationRequired: false,
	publicationText: '',
	responsibleUnit: '',
	referenceProcess: '',
	isDraft: true,
	validFrom: '',
	validUntil: '',
	keywords: '',
}

export default {
	name: 'CaseTypeDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		ArrowLeftIcon,
		CaseTypePublishDialog,
		GeneralTab,
		StatusesTab,
		WorkflowTab,
		ResultsTab,
		RolesTab,
		PropertiesTab,
		DocumentTypesTab,
		DecisionTypesTab,
		SubCaseTypesTab,
		EmailTemplateAdmin,
	},

	props: {
		caseTypeId: {
			type: String,
			default: null,
		},
	},

	emits: ['back', 'duplicated', 'saved'],

	data() {
		return {
			form: { ...EMPTY_FORM },
			activeTab: 'general',
			saving: false,
			saveError: '',
			saveSuccess: false,
			loadingDetail: false,
			validationErrors: {},
			statusTypes: [],
			hasRunningCases: false,
			duplicating: false,
			versioning: false,
			publishDialogOpen: false,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		objectStore() {
			return useObjectStore()
		},

		isCreate() {
			return !this.caseTypeId
		},

		/**
		 * The sentences a refused gesture can carry, already translated.
		 *
		 * Literal t() calls here rather than inside the helper, because
		 * `tests/l10n/check-l10n.js` extracts by finding a literal inside a
		 * t() call for this app: a string that only ever passes through a
		 * callback never reaches a translator.
		 *
		 * @return {object} The messages.
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		refusalMessages() {
			return {
				signIn: t('dossiq', 'Sign in again and retry.'),
				forbidden: t('dossiq', 'Your account may not do this.'),
				missing: t('dossiq', 'This case type no longer exists.'),
				generic: t(
					'dossiq',
					'That did not work. Try again, or ask an administrator.',
				),
			}
		},

		/**
		 * Which version of this case type is open.
		 *
		 * @return {number} The version; one for a type nobody has versioned.
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		version() {
			const version = Number(this.form.version)
			return Number.isFinite(version) && version > 0 ? version : 1
		},

		/**
		 * Whether this is the version new cases are filed on.
		 *
		 * @return {boolean} True while nothing has replaced it.
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		isCurrentVersion() {
			return isCurrentCaseTypeVersion(this.form)
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		tabs() {
			return [
				{ id: 'general', label: t('dossiq', 'General') },
				{ id: 'statuses', label: t('dossiq', 'Statuses') },
				{ id: 'results', label: t('dossiq', 'Results') },
				{ id: 'roles', label: t('dossiq', 'Roles') },
				{ id: 'properties', label: t('dossiq', 'Properties') },
				{ id: 'documents', label: t('dossiq', 'Docs') },
				{ id: 'decisions', label: t('dossiq', 'Decisions') },
				{ id: 'subCaseTypes', label: t('dossiq', 'Sub-cases') },
				{ id: 'workflow', label: t('dossiq', 'Workflow') },
				{ id: 'emailTemplates', label: t('dossiq', 'Email') },
			]
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
	async mounted() {
		if (!this.isCreate) {
			await this.loadCaseType()
		} else {
			this.form.identifier = 'CT-' + Date.now()
		}
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		async loadCaseType() {
			this.loadingDetail = true
			const data = await this.objectStore.fetchObject(
				'caseType',
				this.caseTypeId,
			)
			if (data) {
				this.form = { ...EMPTY_FORM, ...data }
			}
			// Whether ANY case runs on this version, not how many. The store
			// answers a page of rows and no total, so the old count read one
			// row and rendered it as "there are 1 active cases". The banner
			// only ever needed the yes or no.
			try {
				const cases = await this.objectStore.fetchCollection('case', {
					caseType: this.caseTypeId,
					_limit: 1,
				})
				this.hasRunningCases = (cases?.length || 0) > 0
			} catch (e) {
				this.hasRunningCases = false
			}
			this.loadingDetail = false
		},

		/**
		 * @param {object} field The field.
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		onFieldUpdate(field, value) {
			this.form[field] = value
			// Clear validation error for this field
			if (this.validationErrors[field]) {
				const errors = { ...this.validationErrors }
				delete errors[field]
				this.validationErrors = errors
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		async save() {
			this.saveError = ''
			this.saveSuccess = false

			const validation = validateCaseType(this.form)
			this.validationErrors = validation.errors

			if (!validation.valid) {
				this.saveError = t('dossiq', 'Please fix the validation errors')
				return
			}

			this.saving = true
			const result = await this.objectStore.saveObject('caseType', this.form)
			this.saving = false

			if (result) {
				this.saveSuccess = true
				if (this.isCreate && result.id) {
					this.form = { ...EMPTY_FORM, ...result }
					this.$emit('saved', result.id)
				} else {
					this.form = { ...EMPTY_FORM, ...result }
				}
				setTimeout(() => {
					this.saveSuccess = false
				}, 3000)
			} else {
				this.saveError =
					this.objectStore.getError('caseType')
					|| t('dossiq', 'Failed to save case type')
			}
		},

		/**
		 * Reload after the publish dialog closes, whether it published or not.
		 *
		 * 🔴 PUBLISHING IS THE SERVER'S, AND THIS PAGE USED TO DO IT ITSELF.
		 * It validated in the browser and wrote `isDraft: false` straight to
		 * the store, while the in-app case type page called
		 * `POST /api/case-types/{id}/publish`. Two implementations of one
		 * gesture, and only the server one closes the version being replaced,
		 * so publishing from here left two versions of a case type both open
		 * for new cases with nothing to say which was current. One path now,
		 * and it is the one that owns the write.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		async onPublishDialogClosed() {
			this.publishDialogOpen = false
			await this.loadCaseType()
		},

		/**
		 * Start the next version of this case type and navigate to it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/zaaktype-versioning/spec.md
		 */
		async newVersion() {
			this.saveError = ''
			this.versioning = true
			try {
				const response = await axios.post(
					generateUrl(
						'/apps/dossiq/api/case-definitions/{id}/new-version',
						{ id: this.caseTypeId },
					),
				)
				const newId = response.data?.id
				if (newId) {
					this.$emit('duplicated', newId)
				}
			} catch (err) {
				this.saveError = publishRefusalMessage(err, this.refusalMessages)
			} finally {
				this.versioning = false
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		async unpublish() {
			const confirmed = confirm(
				t(
					'dossiq',
					'Unpublishing this case type will prevent new cases from being created. Existing cases will continue to function. Continue?',
				),
			)
			if (!confirmed) return

			this.form.isDraft = true
			await this.save()
		},

		/**
		 * Deep-copy this case type into a new draft, then navigate to it.
		 *
		 * @spec openspec/changes/zaaktype-copy/tasks.md#T11
		 */
		async duplicate() {
			this.saveError = ''
			this.duplicating = true
			try {
				const response = await axios.post(
					generateUrl('/apps/dossiq/api/case-definitions/{id}/copy', {
						id: this.caseTypeId,
					}),
				)
				const newId = response.data?.id
				if (newId) {
					this.$emit('duplicated', newId)
				}
			} catch (err) {
				this.saveError = publishRefusalMessage(err, this.refusalMessages)
			} finally {
				this.duplicating = false
			}
		},
	},
}
</script>

<style scoped>
.case-type-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.case-type-detail__title {
	flex: 1;
	margin: 0;
}

.case-type-detail__actions {
	display: flex;
	gap: 8px;
}

.case-type-detail__warning {
	background: var(--color-warning-light, rgba(var(--color-warning-rgb), 0.1));
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 16px;
	color: var(--color-warning-text);
}

.case-type-detail__version {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	align-self: center;
}

.case-type-detail__error {
	color: var(--color-error);
	margin-bottom: 12px;
}

.case-type-detail__success {
	color: var(--color-success);
	margin-bottom: 12px;
}

.case-type-detail__tabs {
	display: flex;
	gap: 0;
	border-bottom: 2px solid var(--color-border);
	margin-bottom: 20px;
}

.case-type-detail__tab {
	padding: 8px 16px;
	border: none;
	background: none;
	cursor: pointer;
	font-size: 14px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
}

.case-type-detail__tab:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.case-type-detail__tab--active {
	color: var(--color-primary);
	border-bottom-color: var(--color-primary);
}
</style>
