<?php

/**
 * Procest Public Appointment Controller
 *
 * Controller for public (unauthenticated) access to appointments via token.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AppointmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for public (unauthenticated) access to appointments.
 *
 * All endpoints are accessible without Nextcloud authentication.
 * Access is controlled via cryptographically secure tokens.
 */
class PublicAppointmentController extends Controller
{

    /**
     * Constructor for the PublicAppointmentController.
     *
     * @param IRequest           $request            The request object
     * @param AppointmentService $appointmentService The appointment service
     * @param IL10N              $l10n               The localization service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private AppointmentService $appointmentService,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * View an appointment by token.
     *
     * @param string $token The appointment token.
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    public function view(string $token): JSONResponse
    {
        $appointment = $this->appointmentService->getAppointmentByToken($token);
        if ($appointment === null) {
            return new JSONResponse(['error' => $this->l10n->t('Appointment not found')], 404);
        }

        return new JSONResponse([
            'success'     => true,
            'appointment' => [
                'dateTime'   => $appointment['dateTime'] ?? null,
                'duration'   => $appointment['duration'] ?? 30,
                'status'     => $appointment['status'] ?? 'scheduled',
                'locationId' => $appointment['locationId'] ?? null,
                'productId'  => $appointment['productId'] ?? null,
            ],
        ]);
    }//end view()

    /**
     * Cancel an appointment by token.
     *
     * @param string $token The appointment token.
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    public function cancel(string $token): JSONResponse
    {
        $appointment = $this->appointmentService->getAppointmentByToken($token);
        if ($appointment === null) {
            return new JSONResponse(['error' => $this->l10n->t('Appointment not found')], 404);
        }

        if ($appointment['status'] === 'cancelled') {
            return new JSONResponse(['error' => $this->l10n->t('Appointment is already cancelled')], 400);
        }

        $id     = $appointment['uuid'] ?? $appointment['id'] ?? '';
        $result = $this->appointmentService->cancelAppointment($id);
        return new JSONResponse(['success' => true, 'appointment' => $result]);
    }//end cancel()

}//end class
