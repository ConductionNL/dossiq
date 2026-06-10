<?php

/**
 * Vergunning Status Changed Event
 *
 * Domain event dispatched after a vergunningaanvraag status transition.
 * Listeners may use this event to trigger notifications, sync to DSO
 * external systems, or update audit trails.
 *
 * @category Event
 * @package  OCA\Procest\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Event;

use OCP\EventDispatcher\Event;

/**
 * Event raised after each vergunningaanvraag status transition.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T01
 */
class VergunningStatusChangedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string      $vergunningaanvraagRef The vergunningaanvraag UUID reference
     * @param string      $oldStatus             The previous status value
     * @param string      $newStatus             The new status value
     * @param string|null $besluitdatum          Optional decision date (ISO 8601)
     * @param string|null $toelichting           Optional explanation text
     * @param string      $userId                The Nextcloud user UID who triggered the transition
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T01
     */
    public function __construct(
        private readonly string $vergunningaanvraagRef,
        private readonly string $oldStatus,
        private readonly string $newStatus,
        private readonly ?string $besluitdatum,
        private readonly ?string $toelichting,
        private readonly string $userId,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the vergunningaanvraag reference UUID.
     *
     * @return string
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T01
     */
    public function getVergunningaanvraagRef(): string
    {
        return $this->vergunningaanvraagRef;
    }//end getVergunningaanvraagRef()

    /**
     * Get the previous status.
     *
     * @return string
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T01
     */
    public function getOldStatus(): string
    {
        return $this->oldStatus;
    }//end getOldStatus()

    /**
     * Get the new status.
     *
     * @return string
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T01
     */
    public function getNewStatus(): string
    {
        return $this->newStatus;
    }//end getNewStatus()

    /**
     * Get the optional decision date.
     *
     * @return string|null
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T01
     */
    public function getBesluitdatum(): ?string
    {
        return $this->besluitdatum;
    }//end getBesluitdatum()

    /**
     * Get the optional explanation text.
     *
     * @return string|null
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T01
     */
    public function getToelichting(): ?string
    {
        return $this->toelichting;
    }//end getToelichting()

    /**
     * Get the Nextcloud user UID who triggered the transition.
     *
     * @return string
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T01
     */
    public function getUserId(): string
    {
        return $this->userId;
    }//end getUserId()
}//end class
