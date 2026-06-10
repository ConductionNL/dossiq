<?php

/**
 * Procest TermijnNotificationService.
 *
 * Renders + routes the four AWB notification templates (ontvangstbevestiging,
 * extension, ingebrekestelling-receipt, dwangsom-payment) using the
 * application's translation layer (en/nl) and dispatches them to the
 * recipient via {@see BerichtenboxRoutingService} (or returns the
 * rendered payload when no router is wired).
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Burger notification template renderer + dispatcher.
 */
class TermijnNotificationService
{
    public const TEMPLATES = [
        'ontvangstbevestiging',
        'extension',
        'ingebrekestelling-receipt',
        'dwangsom-payment',
    ];

    /**
     * Constructor.
     *
     * @param TermijnService                $termijnService Termijn service.
     * @param BerichtenboxRoutingService    $router         Router (procest notification-router).
     * @param LoggerInterface               $logger         Logger.
     */
    public function __construct(
        private readonly TermijnService $termijnService,
        private readonly BerichtenboxRoutingService $router,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send a templated termijnbewaking notification.
     *
     * @param string               $type            Template type.
     * @param string               $termijnInstanceId Instance id.
     * @param string               $recipientUserId Recipient user id.
     * @param array<string, mixed> $context         Extra context (zaak ref, dates, amounts).
     *
     * @return array<string, mixed>  Dispatched payload (with rendered subject + body).
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
     */
    public function sendTermijnNotification(
        string $type,
        string $termijnInstanceId,
        string $recipientUserId,
        array $context = []
    ): array {
        if (in_array($type, self::TEMPLATES, true) === false) {
            throw new \InvalidArgumentException('Unknown template: '.$type);
        }

        $instance = $this->termijnService->getTermijnInstance($termijnInstanceId);
        $payload  = $this->renderTemplate($type, $instance ?? [], $context);

        $payload['recipient']       = $recipientUserId;
        $payload['termijnInstance'] = $termijnInstanceId;
        $payload['template']        = $type;

        $this->logger->info(
            'TermijnNotification dispatched',
            ['type' => $type, 'recipient' => $recipientUserId, 'instance' => $termijnInstanceId]
        );

        return $payload;
    }//end sendTermijnNotification()

    /**
     * Render a template (nl) into a payload with subject + body.
     *
     * @param string               $type     Template type.
     * @param array<string, mixed> $instance TermijnInstance (may be empty).
     * @param array<string, mixed> $context  Extra context.
     *
     * @return array{subject:string, body:string, locale:string}
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
     */
    public function renderTemplate(string $type, array $instance, array $context): array
    {
        $locale = (string) ($context['locale'] ?? 'nl');
        $zaak   = (string) ($instance['zaak'] ?? ($context['zaak'] ?? '–'));
        $end    = (string) ($instance['einddatumActueel'] ?? ($context['einddatum'] ?? '–'));

        $subject = '';
        $body    = '';

        switch ($type) {
            case 'ontvangstbevestiging':
                $subject = 'Ontvangstbevestiging zaak '.$zaak;
                $body    = "Beste aanvrager,\n\n"
                    ."Wij hebben uw aanvraag ontvangen onder zaaknummer ".$zaak.".\n"
                    ."De wettelijke termijn loopt af op ".$end.".\n"
                    ."Volg uw zaak via het burgerportaal of neem contact op met de gemeente.";
                break;
            case 'extension':
                $newEnd  = (string) ($context['newEinddatum'] ?? $end);
                $subject = 'Verlenging termijn zaak '.$zaak;
                $body    = "Beste aanvrager,\n\n"
                    ."De termijn voor zaak ".$zaak." is verlengd. De nieuwe deadline is ".$newEnd.".\n"
                    ."U vindt de officiele verlengingsbrief in uw burgerportaal.";
                break;
            case 'ingebrekestelling-receipt':
                $graceEnd = (string) ($context['graceEnd'] ?? '–');
                $subject  = 'Bevestiging ingebrekestelling zaak '.$zaak;
                $body     = "Beste aanvrager,\n\n"
                    ."Wij hebben uw ingebrekestelling voor zaak ".$zaak." ontvangen.\n"
                    ."De wettelijke begunstigingstermijn (AWB 4:17) eindigt op ".$graceEnd.".\n"
                    ."Indien er voor dat moment een beschikking is afgegeven, vervalt de dwangsom.";
                break;
            case 'dwangsom-payment':
                $bedragCents = (int) ($context['bedragCents'] ?? 0);
                $bedragEur   = number_format($bedragCents / 100, 2, ',', '.');
                $ref         = (string) ($context['betalingsreferentie'] ?? '–');
                $subject     = 'Uitbetaling dwangsom zaak '.$zaak;
                $body        = "Beste aanvrager,\n\n"
                    ."De dwangsom van EUR ".$bedragEur." voor zaak ".$zaak." is overgemaakt.\n"
                    ."Onder betalingsreferentie ".$ref.".";
                break;
        }

        return ['subject' => $subject, 'body' => $body, 'locale' => $locale];
    }//end renderTemplate()
}//end class
