<?php

/**
 * The timeline kinds dossiq writes, and the standard notes it seeds.
 *
 * ONE LIST, READ BY THREE PLACES: the repair step that declares the kinds,
 * the writers that name one, and the tests that check a writer names a kind
 * that was actually declared. An undeclared kind is a 400 from OpenRegister
 * rather than a plain note, deliberately, so a typo here is a refusal at the
 * first write instead of a log that quietly loses its shape.
 *
 * WHY THERE IS NO `notitie` KIND. OpenRegister projects every note written
 * through `/notes` as a timeline entry with NO kind. Declaring a `notitie`
 * kind beside that would split one log into two filter buckets meaning the
 * same thing, and a handler filtering on "Notitie" would miss every note the
 * notes tab wrote. The reader labels an entry with no kind as a note.
 *
 * THE CONTACT MOMENT'S VOCABULARY IS DOSSIQ'S OWN, NOT THE CONTRACT'S
 * EXAMPLE. OpenRegister's note for consumers suggests declaring channel as
 * telefoon/balie/email/post and direction as inkomend/uitgaand. dossiq's
 * `contactmoment` schema has stored `phone`/`email`/`webformulier`/`chat`/
 * `social_media`/`balie` and `inbound`/`outbound` since the Dutch value
 * rename, and `ContactMomentService::validateInput()` enforces the first
 * list. A kind declaring the other spelling would refuse every entry this
 * app writes, and the refusal is caught and logged rather than shown, so the
 * timeline would simply stop filling. The declaration follows the data.
 *
 * A FIELD THE KIND DOES NOT DECLARE IS DROPPED, NOT REFUSED. That is why
 * `statuswijziging` gained `actor`, `explanation` and `label`, and why
 * `termijngebeurtenis` gained `occurredAt`, `startedAt` and `basis`: the two
 * writers that landed after the first seven kinds carry those values, and
 * without the declaration OpenRegister would have stored the entry, answered
 * 200 and quietly thrown the values away. A declaration only reaches an
 * instance when the repair step runs again, so a change to this list moves the
 * version in `appinfo/info.xml` with it.
 *
 * WHY ONLY INBOUND MAIL CARRIES A FOLLOW-UP. `carriesFollowUp` opens a
 * follow-up on EVERY entry of that kind, so a kind may declare one only when
 * every entry of it genuinely needs an answer. A message that reached a case
 * is open until a handler says otherwise. A logged phone call usually is not:
 * most are answered while the handler is still on the phone, and opening a
 * follow-up on each would make the follow-up count mean nothing.
 * The applicant's own portal acts carry one for the reason mail does: each is
 * something the applicant sent, and it is open until a handler has seen it.
 * So does a portal message from the applicant (`portaalbericht-inkomend`).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timeline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timeline;

use OCA\Dossiq\Kernel\Contracts\TimelineKindsInterface;

/**
 * The declarations dossiq puts on an instance once.
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */
final class TimelineKinds implements TimelineKindsInterface {

}//end class
