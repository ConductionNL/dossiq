<?php

/**
 * Decidesk DecisionRequestedEvent test stub.
 *
 * Mirrors decidesk's merged event contract so procest's delegation services can
 * be unit-tested without the decidesk app installed. The real class ships in
 * decidesk (`OCA\Decidesk\Event\DecisionRequestedEvent`); this stub is loaded
 * by tests/bootstrap.php only when the real class is absent.
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
 * @link https://decidesk.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched by a consuming app to request a decidesk Decision. The decidesk
 * in-process listener writes the result back via setHandled()/setDecisionId().
 */
class DecisionRequestedEvent extends Event
{

    /**
     * Whether decidesk handled the request.
     */
    private bool $handled = false;

    /**
     * The created decidesk decision id (null until handled).
     */
    private ?string $decisionId = null;

    /**
     * Constructor.
     *
     * @param string              $sourceApp         The requesting app id.
     * @param string              $subjectRegister   The subject register.
     * @param string              $subjectSchema     The subject schema.
     * @param string              $subjectId         The subject object id.
     * @param string              $subjectLabel      A human-readable subject label.
     * @param string              $decisionType      The decision type slug.
     * @param string              $actorId           The requesting actor id.
     * @param array<string,mixed> $payload           The decision body payload.
     * @param string              $externalReference The external reference.
     * @param string              $correlationId     The correlation id.
     */
    public function __construct(
        private readonly string $sourceApp,
        private readonly string $subjectRegister,
        private readonly string $subjectSchema,
        private readonly string $subjectId,
        private readonly string $subjectLabel='',
        private readonly string $decisionType='contract',
        private readonly string $actorId='',
        private readonly array $payload=[],
        private readonly string $externalReference='',
        private readonly string $correlationId='',
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * @return string The requesting app id.
     */
    public function getSourceApp(): string
    {
        return $this->sourceApp;
    }//end getSourceApp()

    /**
     * @return string The subject register.
     */
    public function getSubjectRegister(): string
    {
        return $this->subjectRegister;
    }//end getSubjectRegister()

    /**
     * @return string The subject schema.
     */
    public function getSubjectSchema(): string
    {
        return $this->subjectSchema;
    }//end getSubjectSchema()

    /**
     * @return string The subject object id.
     */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }//end getSubjectId()

    /**
     * @return string The subject label.
     */
    public function getSubjectLabel(): string
    {
        return $this->subjectLabel;
    }//end getSubjectLabel()

    /**
     * @return string The decision type slug.
     */
    public function getDecisionType(): string
    {
        return $this->decisionType;
    }//end getDecisionType()

    /**
     * @return string The requesting actor id.
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }//end getActorId()

    /**
     * @return array<string,mixed> The decision body payload.
     */
    public function getPayload(): array
    {
        return $this->payload;
    }//end getPayload()

    /**
     * @return string The external reference.
     */
    public function getExternalReference(): string
    {
        return $this->externalReference;
    }//end getExternalReference()

    /**
     * @return string The correlation id.
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }//end getCorrelationId()

    /**
     * @return bool Whether decidesk handled the request.
     */
    public function isHandled(): bool
    {
        return $this->handled;
    }//end isHandled()

    /**
     * Mark the request handled (called by the decidesk listener).
     *
     * @param bool $handled Whether the request was handled.
     *
     * @return void
     */
    public function setHandled(bool $handled): void
    {
        $this->handled = $handled;
    }//end setHandled()

    /**
     * @return string|null The created decision id, or null.
     */
    public function getDecisionId(): ?string
    {
        return $this->decisionId;
    }//end getDecisionId()

    /**
     * Record the created decision id (called by the decidesk listener).
     *
     * @param string|null $decisionId The created decision id.
     *
     * @return void
     */
    public function setDecisionId(?string $decisionId): void
    {
        $this->decisionId = $decisionId;
    }//end setDecisionId()
}//end class
