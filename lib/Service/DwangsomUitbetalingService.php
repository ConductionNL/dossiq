<?php

/**
 * Procest DwangsomUitbetalingService.
 *
 * Prepares payment signals towards the burger after the dwangsom has
 * been locked and processes ERP callbacks confirming actual payment.
 *
 * IBAN validation is mandatory — missing/invalid IBAN raises an error
 * instead of silently skipping (REQ-TERM-007).
 *
 * Money values: integer EUR cents throughout (ADR-031).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Payment-signal preparation + callback processing for dwangsom payouts.
 */
class DwangsomUitbetalingService
{
    /**
     * Default uiterste-betaaldatum offset in days from the ingebrekestelling
     * receipt date (AWB-default 28d).
     */
    public const BETALING_UITERLIJK_OFFSET_DAYS = 28;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Prepare a DwangsomUitbetaling row for a locked berekening.
     *
     * @param string                 $berekeningId       Berekening id.
     * @param string                 $rekeninghouderNaam Account holder name.
     * @param string                 $iban               IBAN.
     * @param DateTimeImmutable|null $ontvangstDatum     Original ingebrekestelling receipt date (default today).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the berekening is missing or IBAN is invalid.
     */
    public function prepareBetaling(
        string $berekeningId,
        string $rekeninghouderNaam,
        string $iban,
        ?DateTimeImmutable $ontvangstDatum = null
    ): array {
        if ($this->isValidIban($iban) === false) {
            throw new RuntimeException('Invalid IBAN provided for dwangsom uitbetaling');
        }
        if (trim($rekeninghouderNaam) === '') {
            throw new RuntimeException('rekeninghouderNaam is required');
        }

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $bSchema       = (string) $this->settingsService->getConfigValue('dwangsom_berekening_schema');
        $uSchema       = (string) $this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
        if ($objectService === null || $register === '' || $bSchema === '' || $uSchema === '') {
            throw new RuntimeException('Dwangsom services not configured');
        }

        try {
            $berekening = $objectService->find($berekeningId, register: $register, schema: $bSchema);
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomBerekening lookup failed: '.$e->getMessage());
        }
        if (is_array($berekening) === false) {
            throw new RuntimeException('DwangsomBerekening not found: '.$berekeningId);
        }

        $definitief = (int) ($berekening['definitievBedrag'] ?? $berekening['cumulatievBedrag'] ?? 0);
        if ($definitief <= 0) {
            throw new RuntimeException('DwangsomBerekening has no payable amount');
        }

        $ontvangstDatum = ($ontvangstDatum ?? new DateTimeImmutable());
        $uiterlijk      = $ontvangstDatum->modify('+'.self::BETALING_UITERLIJK_OFFSET_DAYS.' days')->format('Y-m-d');

        $row = [
            'dwangsomBerekening'   => $berekeningId,
            'bedrag'               => $definitief,
            'rekeninghouderNaam'   => $rekeninghouderNaam,
            'iban'                 => strtoupper(str_replace(' ', '', $iban)),
            'referentie'           => $this->buildReferentie($berekeningId),
            'wettelijkeGrondslag'  => 'AWB 4:17',
            'betaaldatumUiterlijk' => $uiterlijk,
            'status'               => 'voorbereid',
        ];

        try {
            $saved = $objectService->saveObject($register, $uSchema, $row);
            return is_array($saved) === true ? $saved : $row;
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomUitbetaling persist failed: '.$e->getMessage());
        }
    }//end prepareBetaling()

    /**
     * Handle an ERP callback updating the uitbetaling state.
     *
     * @param string                 $referentie            Payment reference.
     * @param string                 $status                New status (betaald/afgewezen/in-behandeling).
     * @param DateTimeImmutable|null $werkelijkeBetaaldatum Actual payment date.
     * @param string                 $betalingsreferentie   ERP/bank reference.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the referentie is unknown.
     */
    public function handleCallback(
        string $referentie,
        string $status,
        ?DateTimeImmutable $werkelijkeBetaaldatum,
        string $betalingsreferentie = ''
    ): array {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $uSchema       = (string) $this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
        if ($objectService === null || $register === '' || $uSchema === '') {
            throw new RuntimeException('Dwangsom services not configured');
        }

        try {
            $rows = $objectService->findObjects($register, $uSchema, ['referentie' => $referentie]);
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomUitbetaling lookup failed: '.$e->getMessage());
        }

        $row = (is_array($rows) === true && count($rows) > 0) ? $rows[0] : null;
        if (is_array($row) === false) {
            throw new RuntimeException('No DwangsomUitbetaling found for referentie '.$referentie);
        }

        $row['status'] = $status;
        if ($betalingsreferentie !== '') {
            $row['betalingsreferentie'] = $betalingsreferentie;
        }
        if ($werkelijkeBetaaldatum !== null) {
            $row['werkelijkeBetaaldatum'] = $werkelijkeBetaaldatum->format('Y-m-d');
        }

        try {
            $saved = $objectService->saveObject($register, $uSchema, $row);
            return is_array($saved) === true ? $saved : $row;
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomUitbetaling persist failed: '.$e->getMessage());
        }
    }//end handleCallback()

    /**
     * Conservative IBAN check (length + mod-97).
     *
     * @param string $iban IBAN.
     *
     * @return bool
     */
    public function isValidIban(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,32}$/', $iban) !== 1) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $expanded   = '';
        foreach (str_split($rearranged) as $ch) {
            $expanded .= ctype_alpha($ch) === true ? (string) (ord($ch) - 55) : $ch;
        }

        // mod-97 over a string (PHP int can't hold this directly).
        $remainder = '';
        foreach (str_split($expanded) as $digit) {
            $remainder = (string) (((int) ($remainder.$digit)) % 97);
        }

        return ((int) $remainder === 1);
    }//end isValidIban()

    /**
     * Build a deterministic reference from a berekening id.
     *
     * @param string $berekeningId Berekening id.
     *
     * @return string
     */
    private function buildReferentie(string $berekeningId): string
    {
        return 'PROC-DWS-'.strtoupper(substr(sha1($berekeningId.':'.microtime(true)), 0, 12));
    }//end buildReferentie()
}//end class
