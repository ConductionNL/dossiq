<?php

/**
 * Procest Vergunning Status Changed Event
 *
 * Typed domain event dispatched whenever a DSO omgevingsvergunning zaak
 * transitions to a new status. OpenConnector listens to this event to push
 * the status update back to DSO-LV.
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
 * Event raised after every DSO vergunningaanvraag status transition.
 *
 * Consumed by OpenConnector to push the updated status back to DSO-LV.
 */
class VergunningStatusChangedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string      $vergunningaanvraagRef OpenRegister reference to the vergunningaanvraag
     * @param string      $oldStatus             Previous DSO status value
     * @param string      $newStatus             New DSO status value
     * @param string|null $besluitdatum          Decision date (verleend/geweigerd)
     * @param string|null $toelichting           Decision motivation or rejection reason
     * @param string      $userId                Nextcloud UID of the acting user (audit)
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
     */
    public function getVergunningaanvraagRef(): string
    {
        return $this->vergunningaanvraagRef;
    }//end getVergunningaanvraagRef()

    /**
     * Get the previous status.
     *
     * @return string
     */
    public function getOldStatus(): string
    {
        return $this->oldStatus;
    }//end getOldStatus()

    /**
     * Get the new status.
     *
     * @return string
     */
    public function getNewStatus(): string
    {
        return $this->newStatus;
    }//end getNewStatus()

    /**
     * Get the optional decision date.
     *
     * @return string|null
     */
    public function getBesluitdatum(): ?string
    {
        return $this->besluitdatum;
    }//end getBesluitdatum()

    /**
     * Get the optional decision motivation.
     *
     * @return string|null
     */
    public function getToelichting(): ?string
    {
        return $this->toelichting;
    }//end getToelichting()

    /**
     * Get the Nextcloud UID of the acting user.
     *
     * @return string
     */
    public function getUserId(): string
    {
        return $this->userId;
    }//end getUserId()
}//end class
