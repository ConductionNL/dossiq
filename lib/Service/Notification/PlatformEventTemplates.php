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

use OCA\Dossiq\Kernel\Contracts\PlatformEventTemplatesInterface;

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
final class PlatformEventTemplates implements PlatformEventTemplatesInterface {

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
