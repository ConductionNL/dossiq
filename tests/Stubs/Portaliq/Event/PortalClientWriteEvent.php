<?php

/**
 * Portaliq PortalClientWriteEvent test stub.
 *
 * Mirrors portaliq's contract verbatim (constructor parameter names AND order,
 * the constants and every getter) so the dossiq listener can be unit-tested
 * without the portaliq app installed. The real class ships in portaliq
 * (`lib/Event/PortalClientWriteEvent.php`), read at `development` on 2026-09-27; this stub
 * is loaded by tests/bootstrap.php only when the real class is absent.
 *
 * @category Tests
 * @package  OCA\Portaliq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * A citizen write on their own case, raised once per act.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class PortalClientWriteEvent extends Event {
	/**
	 * The canonical name of this fact, as the spec writes it. Dispatch is
	 * typed (one dispatch, so a bound rule fires exactly once); this constant
	 * is the name the event carries and is what a case app matches on when it
	 * logs or routes the fact.
	 */
	public const NAME = 'portal.write.client';

	/**
	 * The act that amends answers already given.
	 */
	public const ACT_AMENDMENT = 'amendment';

	/**
	 * The act that adds a document to a running case.
	 */
	public const ACT_DOCUMENT = 'document';

	/**
	 * The act that answers a task the case raised.
	 */
	public const ACT_TASK_ANSWER = 'task-answer';

	/**
	 * Constructor.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $caseId The case the write landed on.
	 * @param string $act One of the ACT_* constants.
	 * @param array<int, string> $fields The field names the act touched.
	 * @param array<string, mixed> $identity The portal identity that wrote.
	 * @param array<string, mixed> $mandate The mandate the write acted under.
	 * @param string $occurredAt The moment of the write, ISO 8601.
	 */
	public function __construct(
		private readonly string $register,
		private readonly string $schema,
		private readonly string $caseId,
		private readonly string $act,
		private readonly array $fields,
		private readonly array $identity,
		private readonly array $mandate,
		private readonly string $occurredAt,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The register the case lives in.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function getRegister(): string {
		return $this->register;
	}//end getRegister()

	/**
	 * The schema the case lives in.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function getSchema(): string {
		return $this->schema;
	}//end getSchema()

	/**
	 * The case the write landed on.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function getCaseId(): string {
		return $this->caseId;
	}//end getCaseId()

	/**
	 * The act: amendment, document or task answer.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function getAct(): string {
		return $this->act;
	}//end getAct()

	/**
	 * The field names the act touched.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function getFields(): array {
		return $this->fields;
	}//end getFields()

	/**
	 * The portal identity that wrote: subjectRef, audience, trust and jti.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function getIdentity(): array {
		return $this->identity;
	}//end getIdentity()

	/**
	 * The mandate the write acted under: the declared action that granted it,
	 * the audience it was granted to and the assurance level it required.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function getMandate(): array {
		return $this->mandate;
	}//end getMandate()

	/**
	 * The moment of the write, ISO 8601.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function getOccurredAt(): string {
		return $this->occurredAt;
	}//end getOccurredAt()
}//end class
