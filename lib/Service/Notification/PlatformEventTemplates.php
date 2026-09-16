<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

/**
 * Dutch wording for the platform events a case instance raises.
 *
 * 🔴 THESE FILL GAPS, THEY DO NOT OVERRULE. OpenRegister's template store is
 * app config on `openregister`, so one text serves every app on the instance.
 * Writing case wording over a template pipelinq or opencatalogi is already
 * using would relabel their notices, silently. So the repair step writes a
 * template only for an event `GET /api/notification-templates/gaps` reports as
 * having none, and an administrator's edit is never touched.
 *
 * WHY THE LIST IS NOT DERIVED FROM THE EVENT INVENTORY. Deriving it would make
 * the gap list unfillable-by-construction, which is the same as not having one.
 * This is dossiq's own answer to a question the platform asks; where the two
 * disagree, the gap list is the question and this is one possible answer.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
 */
final class PlatformEventTemplates {

	/**
	 * The Dutch text dossiq offers for each platform event it raises.
	 *
	 * The variable names are the ones the event declares in the `variables` map
	 * of `GET /api/notification-templates`. A token this event does not carry
	 * renders as itself, which is why each entry names only that event's own.
	 *
	 * @var array<string, array{subject: string, body: string}>
	 */
	public const DUTCH = [
		'object_created' => [
			'subject' => 'Nieuw: {{title}}',
			'body' => '{{actor}} heeft {{title}} aangemaakt in {{schema}}. Open het om te zien wat erin staat.',
		],
		'object_updated' => [
			'subject' => '{{title}} is gewijzigd',
			'body' => '{{actor}} heeft {{title}} in {{schema}} gewijzigd. Bekijk wat er anders is.',
		],
		'object_transitioned' => [
			'subject' => '{{title}}: van {{from}} naar {{to}}',
			'body' => '{{title}} staat nu op {{to}} en was {{from}}. Kijk of er iets van je wordt verwacht.',
		],
		'retention_holds_skipped' => [
			'subject' => 'Bewaartermijn: {{skippedCount}} records overgeslagen',
			'body' => 'De opschoning van {{schemaSlug}} liet {{skippedCount}} records staan. Ze liggen vast onder een blokkade.',
		],
		'destruction_holds_skipped' => [
			'subject' => 'Vernietiging: {{skippedCount}} records overgeslagen',
			'body' => 'De vernietiging van {{schemaSlug}} liet {{skippedCount}} records staan. Ze liggen vast onder een blokkade.',
		],
		'destruction_review_pending' => [
			'subject' => '{{pendingCount}} records wachten op een beoordelaar',
			'body' => 'Van {{schemaSlug}} wachten {{pendingCount}} records op iemand die de vernietiging beoordeelt.',
		],
	];

	/**
	 * The Dutch text for one event, or null when dossiq offers none.
	 *
	 * @param string $event The platform event name.
	 *
	 * @return array{subject: string, body: string}|null The text.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public static function dutchFor(string $event): ?array {
		return (self::DUTCH[$event] ?? null);
	}//end dutchFor()
}//end class
