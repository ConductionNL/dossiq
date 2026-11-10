<?php

/**
 * A just-created object of one schema that names the case it belongs to.
 *
 * Listeners that mirror a new record onto its case (a contact moment onto the
 * case timeline, onto pipelinq) first ask the same three things of the event:
 * is it a create, is the object of their schema, does it name its `case`.
 * This answers them once.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;

/**
 * Reads the created object of a schema, when it names its case.
 *
 * Built by the listener that asks, with its own schema slug.
 *
 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md
 */
final class CreatedCaseRecord {

	/**
	 * Constructor.
	 *
	 * @param ObjectSchemaSlugResolver $slugResolver Resolves the schema slug from a payload.
	 * @param string                   $schema       The schema slug the listener acts on.
	 */
	public function __construct(
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly string $schema,
	) {
	}//end __construct()

	/**
	 * The created object, when it is of the schema and names a case under `case`.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md
	 */
	public function of(Event $event): ?array {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return null;
		}

		$payload = EventObjectPayload::of(event: $event);
		if ($payload === null || $this->slugResolver->resolveFromPayload(payload: $payload) !== $this->schema) {
			return null;
		}

		if (trim((string)($payload['case'] ?? '')) === '') {
			return null;
		}

		return $payload;
	}//end of()
}//end class
