<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for hermiq's published cross-app contract.
 *
 * procest resolves `\OCA\Hermiq\Event\AiOversightRecordedEvent` by NAME so it
 * stays installable without hermiq. That means the contract is only exercised
 * in tests if something supplies the class — this is that something, and it
 * MIRRORS hermiq's signature deliberately. If hermiq changes the shape, this
 * stub is where procest finds out.
 *
 * Mirrors: hermiq lib/Event/AiOversightRecordedEvent.php
 *
 * @category Test
 * @package  OCA\Hermiq\Event
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Stub of hermiq's AiOversightRecordedEvent.
 */
class AiOversightRecordedEvent extends Event {

    /**
     * The Approval id hermiq wrote.
     *
     * @var string|null
     */
    private ?string $approvalId = null;

    /**
     * Whether hermiq recorded the decision.
     *
     * @var boolean
     */
    private bool $handled = false;


    /**
     * Construct the event.
     *
     * @param array<string, mixed> $record The decision record.
     *
     * @return void
     */
    public function __construct(private readonly array $record) {
        parent::__construct();

    }//end __construct()


    /**
     * Get the record.
     *
     * @return array<string, mixed> The record.
     */
    public function getRecord(): array {
        return $this->record;

    }//end getRecord()


    /**
     * Get the written Approval id.
     *
     * @return string|null The id, or null.
     */
    public function getApprovalId(): ?string {
        return $this->approvalId;

    }//end getApprovalId()


    /**
     * Set the written Approval id.
     *
     * @param string $approvalId The id.
     *
     * @return void
     */
    public function setApprovalId(string $approvalId): void {
        $this->approvalId = $approvalId;
        $this->handled    = true;

    }//end setApprovalId()


    /**
     * Whether hermiq handled it.
     *
     * @return boolean True when recorded.
     */
    public function isHandled(): bool {
        return $this->handled;

    }//end isHandled()


}//end class
