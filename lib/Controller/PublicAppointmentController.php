<?php

/**
 * Procest Public Appointment Controller.
 *
 * Public (unauthenticated) endpoints for citizens to view or cancel
 * appointments via a token URL.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AppointmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public (citizen-facing) endpoints for appointment view/cancel by token.
 */
class PublicAppointmentController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request            The request object.
     * @param AppointmentService $appointmentService The appointment service.
     */
    public function __construct(
        IRequest $request,
        private AppointmentService $appointmentService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * View an appointment via its public token.
     *
     * @param string $token The appointment public token.
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function view(string $token): JSONResponse
    {
        $appointment = $this->appointmentService->getAppointmentByToken($token);
        if ($appointment === null) {
            return new JSONResponse(['error' => 'Afspraak niet gevonden'], 404);
        }

        return new JSONResponse(
                [
                    'success'     => true,
                    'appointment' => [
                        'dateTime'   => $appointment['dateTime'] ?? null,
                        'duration'   => $appointment['duration'] ?? 30,
                        'status'     => $appointment['status'] ?? 'scheduled',
                        'locationId' => $appointment['locationId'] ?? null,
                        'productId'  => $appointment['productId'] ?? null,
                    ],
                ]
                );
    }//end view()

    /**
     * Cancel an appointment via its public token.
     *
     * @param string $token The appointment public token.
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function cancel(string $token): JSONResponse
    {
        $appointment = $this->appointmentService->getAppointmentByToken($token);
        if ($appointment === null) {
            return new JSONResponse(['error' => 'Afspraak niet gevonden'], 404);
        }

        if ($appointment['status'] === 'cancelled') {
            return new JSONResponse(['error' => 'Afspraak is al geannuleerd'], 400);
        }

        $id     = $appointment['uuid'] ?? $appointment['id'] ?? '';
        $result = $this->appointmentService->cancelAppointment($id);
        return new JSONResponse(['success' => true, 'appointment' => $result]);
    }//end cancel()
}//end class
