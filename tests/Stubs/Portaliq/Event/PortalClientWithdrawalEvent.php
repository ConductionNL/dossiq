<?php

/**
 * Portaliq PortalClientWithdrawalEvent test stub.
 *
 * Mirrors portaliq's contract verbatim (constructor parameter names AND order,
 * the constants and every getter) so the dossiq listener can be unit-tested
 * without the portaliq app installed. The real class ships in portaliq
 * (`lib/Event/PortalClientWithdrawalEvent.php`), read at `development` on 2026-09-27; this stub
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
 * A citizen's withdrawal of their own request.
 *
 * @spec openspec/changes/withdrawing-your-own-case-from-the-portal/specs/withdrawing-your-own-case/spec.md
 */
class PortalClientWithdrawalEvent extends Event {
	/**
	 * The canonical name of this fact, as the spec writes it.
	 */
	public const NAME = 'portal.withdraw.client';

	/**
	 * Constructor.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $caseId The case that was withdrawn.
	 * @param string $status The status it landed on.
	 * @param string $reason What the applicant said, or ''.
	 * @param array<string, mixed> $identity The portal identity that withdrew.
	 * @param array<string, mixed> $mandate The mandate it was made under.
	 * @param string $occurredAt The moment of the withdrawal, ISO 8601.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the event carries the
	 * whole fact; a rule binding to it should need nothing else.
	 */
	public function __construct(
		private readonly string $register,
		private readonly string $schema,
		private readonly string $caseId,
		private readonly string $status,
		private readonly string $reason,
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
	 */
	public function getRegister(): string {
		return $this->register;
	}//end getRegister()

	/**
	 * The schema the case lives in.
	 *
	 * @return string
	 */
	public function getSchema(): string {
		return $this->schema;
	}//end getSchema()

	/**
	 * The case that was withdrawn.
	 *
	 * @return string
	 */
	public function getCaseId(): string {
		return $this->caseId;
	}//end getCaseId()

	/**
	 * The status the withdrawal landed on.
	 *
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * What the applicant said, or ''.
	 *
	 * @return string
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * The portal identity that withdrew.
	 *
	 * @return array<string, mixed>
	 */
	public function getIdentity(): array {
		return $this->identity;
	}//end getIdentity()

	/**
	 * The mandate the withdrawal was made under.
	 *
	 * @return array<string, mixed>
	 */
	public function getMandate(): array {
		return $this->mandate;
	}//end getMandate()

	/**
	 * The moment of the withdrawal, ISO 8601.
	 *
	 * @return string
	 */
	public function getOccurredAt(): string {
		return $this->occurredAt;
	}//end getOccurredAt()
}//end class
