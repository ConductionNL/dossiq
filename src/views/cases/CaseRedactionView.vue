<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The redaction page of a case (`/cases/:id/redact`), built to board DqLakken:
	the redaction panel drawn as a page, with its title, breadcrumb and buttons
	in the page header. The page's manifest config carries the routes and the
	passage type labels, as the header action that opened the dialog did.

	@spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
-->
<template>
	<DocumentRedactionDialog
		:asPage="true"
		:caseId="id"
		:listUrl="listUrl"
		:detectUrl="detectUrl"
		:checkUrl="checkUrl"
		:redactUrl="redactUrl"
		:uploadUrl="uploadUrl"
		:typeLabels="typeLabels" />
</template>

<script>
import DocumentRedactionDialog from '../../dialogs/DocumentRedactionDialog.vue'

export default {
	name: 'CaseRedactionView',

	components: {
		DocumentRedactionDialog,
	},

	props: {
		/** The case, from the route. */
		id: {
			type: String,
			default: '',
		},

		listUrl: {
			type: String,
			default: undefined,
		},

		detectUrl: {
			type: String,
			default: undefined,
		},

		checkUrl: {
			type: String,
			default: undefined,
		},

		redactUrl: {
			type: String,
			default: undefined,
		},

		uploadUrl: {
			type: String,
			default: undefined,
		},

		typeLabels: {
			type: Object,
			default: undefined,
		},
	},
}
</script>
