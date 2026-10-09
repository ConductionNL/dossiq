// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { translate as t } from '@nextcloud/l10n'

/**
 * The words a person reads for each dossiq notification rule in their
 * user settings, instead of the rule key.
 *
 * The keys are `<schema slug>.<rule key>` as OpenRegister lists them on
 * `/api/notification-preferences`: the rules declared under
 * `x-openregister-notifications` in `lib/Settings/dossiq_register.json` and
 * `lib/Settings/register.d/`. CnAppRoot hands the map to the library's
 * notification pane (`notificationLabels`, in @conduction/nextcloud-vue 2.73 and later).
 * A rule missing here still reads as words ("Case assigned"), never as its
 * key, so a new rule never shows "caseAssigned" again; it just reads plainer
 * until it gets a label here.
 *
 * Built on call rather than at import so the strings follow the language the
 * page was loaded in.
 *
 * @return {{[key: string]: string}} Labels keyed `<schema>.<key>` or `<key>`.
 * @spec openspec/changes/notification-labels-and-tour-titles/specs/notification-labels/spec.md
 */
export function notificationLabels() {
	return {
		'case.caseAssigned': t('dossiq', 'A case is assigned to me'),
		'case.caseDeclaredMajor': t('dossiq', 'A case is declared major'),
		'case.caseHandoffIntake': t('dossiq', 'A case arrives through a handoff'),
		'case.caseMovedForItsFollowers': t(
			'dossiq',
			'A case I follow changes status',
		),
		'consultation.onCreate': t('dossiq', 'A consultation is requested'),
		'consultation.onStatusChange': t('dossiq', 'A consultation changes status'),
		'substitution.substitutionRegisteredForSubstitute': t(
			'dossiq',
			'I am registered as a substitute',
		),
		'workDigest.workDigestReady': t('dossiq', 'My work digest is ready'),
		// Listed on instances that still carry a task schema with this rule.
		taskAssigned: t('dossiq', 'A task is assigned to me'),
	}
}
