<?php

/**
 * Decidesk GovernanceBodyRequestedEvent test stub.
 *
 * Mirrors the decision app's cross-app command contract so dossiq's
 * CommitteeDelegationService can be unit-tested without the app installed.
 * The real class ships in decidiq (`OCA\Decidiq\Event\GovernanceBodyRequestedEvent`);
 * this stub is loaded by tests/bootstrap.php only when the real class is absent.
 *
 * 🔴 THE CONSTRUCTOR ORDER IS THE CONTRACT. The production code builds this
 * POSITIONALLY through a class-string, so a stub whose parameters are in a
 * different order would let the suite pass on argument bindings that are wrong
 * in production. Keep it identical to decidiq's.
 *
 * @category Tests
 * @package  OCA\Decidesk\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://decidiq.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Event;

use OCP\EventDispatcher\Event;

/**
 * A consumer app asks the decision app to raise a GovernanceBody.
 */
class GovernanceBodyRequestedEvent extends Event {

	/**
	 * The resolved body id (result slot).
	 *
	 * @var string|null
	 */
	private ?string $governanceBodyId = null;

	/**
	 * Whether the body was newly created (result slot).
	 *
	 * @var boolean
	 */
	private bool $created = false;

	/**
	 * Whether the command was handled (result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The producing app id.
	 * @param string $externalReference The producer's own reference.
	 * @param string $name The body name.
	 * @param string $bodyType The GovernanceBody bodyType.
	 * @param string $domain The governance domain.
	 * @param bool $active Whether the body may be assigned new work.
	 * @param array<string,mixed> $attributes Further body fields.
	 * @param array<int,array<string,mixed>> $members The roster.
	 * @param string $actorId The acting Nextcloud UID.
	 * @param string $correlationId The correlation id.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $externalReference,
		private readonly string $name,
		private readonly string $bodyType,
		private readonly string $domain,
		private readonly bool $active,
		private readonly array $attributes = [],
		private readonly array $members = [],
		private readonly string $actorId = '',
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}

	/** @return string The producing app id. */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}

	/** @return string The producer's own reference. */
	public function getExternalReference(): string {
		return $this->externalReference;
	}

	/** @return string The body name. */
	public function getName(): string {
		return $this->name;
	}

	/** @return string The bodyType. */
	public function getBodyType(): string {
		return $this->bodyType;
	}

	/** @return string The domain. */
	public function getDomain(): string {
		return $this->domain;
	}

	/** @return bool The active flag. */
	public function isActive(): bool {
		return $this->active;
	}

	/** @return array<string,mixed> The attribute bag. */
	public function getAttributes(): array {
		return $this->attributes;
	}

	/** @return array<int,array<string,mixed>> The roster. */
	public function getMembers(): array {
		return $this->members;
	}

	/** @return string The acting uid. */
	public function getActorId(): string {
		return $this->actorId;
	}

	/** @return string The correlation id. */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}

	/** @return string The resolved body id, or an empty string. */
	public function getGovernanceBodyId(): string {
		return ($this->governanceBodyId ?? '');
	}

	/**
	 * @param string $governanceBodyId The resolved id.
	 *
	 * @return void
	 */
	public function setGovernanceBodyId(string $governanceBodyId): void {
		$this->governanceBodyId = $governanceBodyId;
	}

	/** @return bool Whether the body was newly created. */
	public function isCreated(): bool {
		return $this->created;
	}

	/**
	 * @param bool $created Whether the body was newly created.
	 *
	 * @return void
	 */
	public function setCreated(bool $created): void {
		$this->created = $created;
	}

	/** @return bool Whether the command was handled. */
	public function isHandled(): bool {
		return $this->handled;
	}

	/**
	 * @param bool $handled Whether the command was handled.
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}
}
