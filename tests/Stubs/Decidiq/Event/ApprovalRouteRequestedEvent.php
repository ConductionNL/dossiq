<?php

/**
 * Decidiq ApprovalRouteRequestedEvent test stub.
 *
 * Mirrors the decision app's cross-app command contract so dossiq's
 * ParaferingDelegationService can be unit-tested without the app installed.
 *
 * 🔴 THE CONSTRUCTOR ORDER IS THE CONTRACT. The production code builds this
 * POSITIONALLY through a class-string, so a stub whose parameters are in a
 * different order would let the suite pass on argument bindings that are wrong
 * in production. Keep it identical to decidiq's.
 *
 * @category Tests
 * @package  OCA\Decidiq\Event
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

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * A consumer app asks the decision app to hold an approval route.
 */
class ApprovalRouteRequestedEvent extends Event {

	/** @var string|null */
	private ?string $routeId = null;

	/** @var boolean */
	private bool $created = false;

	/** @var integer */
	private int $stageCount = 0;

	/** @var boolean */
	private bool $handled = false;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The producing app id.
	 * @param string $externalReference The producer's own reference.
	 * @param string $name The route name.
	 * @param array<int,array<string,mixed>> $steps The ordered steps.
	 * @param string $subjectType What travels this route.
	 * @param string $description When the route applies.
	 * @param bool $isDefault Whether it is the default.
	 * @param string $subject Optional subject to start travelling.
	 * @param string $subjectSchema That subject's schema slug.
	 * @param string $actorId The acting uid.
	 * @param string $correlationId The correlation id.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $externalReference,
		private readonly string $name,
		private readonly array $steps,
		private readonly string $subjectType = '',
		private readonly string $description = '',
		private readonly bool $isDefault = false,
		private readonly string $subject = '',
		private readonly string $subjectSchema = '',
		private readonly string $actorId = '',
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}

	/** @return string The app id. */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}

	/** @return string The external reference. */
	public function getExternalReference(): string {
		return $this->externalReference;
	}

	/** @return string The name. */
	public function getName(): string {
		return $this->name;
	}

	/** @return array<int,array<string,mixed>> The steps. */
	public function getSteps(): array {
		return $this->steps;
	}

	/** @return string The subject type. */
	public function getSubjectType(): string {
		return $this->subjectType;
	}

	/** @return string The description. */
	public function getDescription(): string {
		return $this->description;
	}

	/** @return bool The default flag. */
	public function isDefault(): bool {
		return $this->isDefault;
	}

	/** @return string The subject. */
	public function getSubject(): string {
		return $this->subject;
	}

	/** @return string The subject schema. */
	public function getSubjectSchema(): string {
		return $this->subjectSchema;
	}

	/** @return string The actor id. */
	public function getActorId(): string {
		return $this->actorId;
	}

	/** @return string The correlation id. */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}

	/** @return string The route id, or an empty string. */
	public function getRouteId(): string {
		return ($this->routeId ?? '');
	}

	/**
	 * @param string $routeId The resolved id.
	 *
	 * @return void
	 */
	public function setRouteId(string $routeId): void {
		$this->routeId = $routeId;
	}

	/** @return bool Whether it was newly created. */
	public function isCreated(): bool {
		return $this->created;
	}

	/**
	 * @param bool $created Whether it was newly created.
	 *
	 * @return void
	 */
	public function setCreated(bool $created): void {
		$this->created = $created;
	}

	/** @return int The stage count. */
	public function getStageCount(): int {
		return $this->stageCount;
	}

	/**
	 * @param int $stageCount The stage count.
	 *
	 * @return void
	 */
	public function setStageCount(int $stageCount): void {
		$this->stageCount = $stageCount;
	}

	/** @return bool Whether it was handled. */
	public function isHandled(): bool {
		return $this->handled;
	}

	/**
	 * @param bool $handled Whether it was handled.
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}
}
