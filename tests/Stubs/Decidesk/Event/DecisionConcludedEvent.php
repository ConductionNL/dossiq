<?php

/**
 * Decidesk DecisionConcludedEvent test stub.
 *
 * Mirrors decidesk's merged event contract so procest's
 * DecisionConcludedListener can be unit-tested without the decidesk app
 * installed. The real class ships in decidesk
 * (`OCA\Decidesk\Event\DecisionConcludedEvent`); this stub is loaded by
 * tests/bootstrap.php only when the real class is absent.
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
 * Dispatched by decidesk when a Decision reaches a terminal state.
 */
class DecisionConcludedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string           $decisionId        The decidesk decision id.
     * @param string           $decisionType      The decision type slug.
     * @param string           $status            approved|rejected|withdrawn|pending.
     * @param string           $outcome           The outcome string.
     * @param bool             $signed            Whether the decision was signed.
     * @param string|null      $signingReference  The signing reference.
     * @param array<int,mixed> $signers           The signers list.
     * @param string|null      $decidedAt         The decided-at timestamp.
     * @param string           $sourceApp         The originating app id.
     * @param string|null      $subjectRegister   The subject register.
     * @param string|null      $subjectSchema     The subject schema.
     * @param string|null      $subjectId         The subject object id.
     * @param string           $externalReference The external reference.
     * @param string           $correlationId     The correlation id.
     */
    public function __construct(
        private readonly string $decisionId,
        private readonly string $decisionType,
        private readonly string $status,
        private readonly string $outcome,
        private readonly bool $signed=false,
        private readonly ?string $signingReference=null,
        private readonly array $signers=[],
        private readonly ?string $decidedAt=null,
        private readonly string $sourceApp='',
        private readonly ?string $subjectRegister=null,
        private readonly ?string $subjectSchema=null,
        private readonly ?string $subjectId=null,
        private readonly string $externalReference='',
        private readonly string $correlationId='',
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * @return string The decision id.
     */
    public function getDecisionId(): string
    {
        return $this->decisionId;
    }//end getDecisionId()

    /**
     * @return string The decision type.
     */
    public function getDecisionType(): string
    {
        return $this->decisionType;
    }//end getDecisionType()

    /**
     * @return string The status.
     */
    public function getStatus(): string
    {
        return $this->status;
    }//end getStatus()

    /**
     * @return string The outcome.
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }//end getOutcome()

    /**
     * @return bool Whether the decision was signed.
     */
    public function isSigned(): bool
    {
        return $this->signed;
    }//end isSigned()

    /**
     * @return string|null The signing reference.
     */
    public function getSigningReference(): ?string
    {
        return $this->signingReference;
    }//end getSigningReference()

    /**
     * @return array<int,mixed> The signers.
     */
    public function getSigners(): array
    {
        return $this->signers;
    }//end getSigners()

    /**
     * @return string|null The decided-at timestamp.
     */
    public function getDecidedAt(): ?string
    {
        return $this->decidedAt;
    }//end getDecidedAt()

    /**
     * @return string The source app id.
     */
    public function getSourceApp(): string
    {
        return $this->sourceApp;
    }//end getSourceApp()

    /**
     * @return string|null The subject register.
     */
    public function getSubjectRegister(): ?string
    {
        return $this->subjectRegister;
    }//end getSubjectRegister()

    /**
     * @return string|null The subject schema.
     */
    public function getSubjectSchema(): ?string
    {
        return $this->subjectSchema;
    }//end getSubjectSchema()

    /**
     * @return string|null The subject id.
     */
    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }//end getSubjectId()

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
}//end class
