<?php

/**
 * Procest Vergunning Status Changed Event
 *
 * Typed event dispatched when a Procest zaak's DSO status transitions.
 * Consumed by OpenConnector to push the status update to DSO-LV.
 *
 * @category Event
 * @package  OCA\Procest\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Procest\Event;

use OCP\EventDispatcher\Event;

/**
 * Carries the details of a vergunningaanvraag status transition.
 *
 * Dispatched by DsoCaseService::transitionStatus() after both the Procest
 * zaak and the OpenRegister vergunningaanvraag have been updated.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 */
class VergunningStatusChangedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string      $vergunningaanvraagRef Reference to the vergunningaanvraag object
     * @param string      $oldStatus             Previous DSO status value
     * @param string      $newStatus             New DSO status value
     * @param string|null $besluitdatum          Decision date (for verleend/geweigerd)
     * @param string|null $toelichting           Decision motivation
     * @param string      $userId                Nextcloud user ID of the initiating user
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
     * Get the vergunningaanvraag reference.
     *
     * @return string
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
     */
    public function getVergunningaanvraagRef(): string
    {
        return $this->vergunningaanvraagRef;
    }//end getVergunningaanvraagRef()

    /**
     * Get the previous DSO status.
     *
     * @return string
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
     */
    public function getOldStatus(): string
    {
        return $this->oldStatus;
    }//end getOldStatus()

    /**
     * Get the new DSO status.
     *
     * @return string
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
     */
    public function getNewStatus(): string
    {
        return $this->newStatus;
    }//end getNewStatus()

    /**
     * Get the decision date, if set.
     *
     * @return string|null
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
     */
    public function getBesluitdatum(): ?string
    {
        return $this->besluitdatum;
    }//end getBesluitdatum()

    /**
     * Get the decision motivation or rejection reasoning, if set.
     *
     * @return string|null
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
     */
    public function getToelichting(): ?string
    {
        return $this->toelichting;
    }//end getToelichting()

    /**
     * Get the Nextcloud user ID of the user who triggered the transition.
     *
     * @return string
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
     */
    public function getUserId(): string
    {
        return $this->userId;
    }//end getUserId()
}//end class
