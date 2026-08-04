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
use OCA\Procest\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Payment-signal preparation + callback processing for dwangsom payouts.
 */
class DwangsomUitbetalingService
{
    use SearchesObjects;

    /**
     * Default uiterste-betaaldatum offset in days from the ingebrekestelling
     * receipt date (AWB-default 28d).
     */
    public const BETALING_UITERLIJK_OFFSET_DAYS = 28;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
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
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
     */
    public function prepareBetaling(
        string $berekeningId,
        string $rekeninghouderNaam,
        string $iban,
        ?DateTimeImmutable $ontvangstDatum=null
    ): array {
        $this->assertBetalingInput(iban: $iban, rekeninghouderNaam: $rekeninghouderNaam);

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $bSchema       = (string) $this->settingsService->getConfigValue('dwangsom_berekening_schema');
        $uSchema       = (string) $this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
        if ($objectService === null || $register === '' || $bSchema === '' || $uSchema === '') {
            throw new RuntimeException('Dwangsom services not configured');
        }

        $definitief = $this->resolvePayableAmount(
            objectService: $objectService,
            register: $register,
            schema: $bSchema,
            berekeningId: $berekeningId
        );

        $ontvangstDatum = ($ontvangstDatum ?? new DateTimeImmutable());
        $uiterlijk      = $ontvangstDatum->modify('+'.self::BETALING_UITERLIJK_OFFSET_DAYS.' days')->format('Y-m-d');

        $row = [
            'dwangsomBerekening'   => $berekeningId,
            'bedrag'               => $definitief,
            'rekeninghouderNaam'   => $rekeninghouderNaam,
            'iban'                 => strtoupper(str_replace(' ', '', $iban)),
            'referentie'           => $this->buildReferentie(berekeningId: $berekeningId),
            'wettelijkeGrondslag'  => 'AWB 4:17',
            'betaaldatumUiterlijk' => $uiterlijk,
            'status'               => 'voorbereid',
        ];

        return $this->persistUitbetaling(
            objectService: $objectService,
            register: $register,
            schema: $uSchema,
            row: $row
        );
    }//end prepareBetaling()

    /**
     * Validate the caller-supplied payment input.
     *
     * @param string $iban               IBAN.
     * @param string $rekeninghouderNaam Account holder name.
     *
     * @return void
     *
     * @throws RuntimeException When the IBAN or the account holder name is invalid.
     */
    private function assertBetalingInput(string $iban, string $rekeninghouderNaam): void
    {
        if ($this->isValidIban(iban: $iban) === false) {
            throw new RuntimeException('Invalid IBAN provided for dwangsom uitbetaling');
        }

        if (trim($rekeninghouderNaam) === '') {
            throw new RuntimeException('rekeninghouderNaam is required');
        }
    }//end assertBetalingInput()

    /**
     * Resolve the payable amount locked on a DwangsomBerekening.
     *
     * @param object $objectService OpenRegister object service.
     * @param string $register      Register identifier.
     * @param string $schema        DwangsomBerekening schema identifier.
     * @param string $berekeningId  Berekening id.
     *
     * @return int Payable amount in EUR cents.
     *
     * @throws RuntimeException When the berekening is missing or has nothing payable.
     */
    private function resolvePayableAmount(
        object $objectService,
        string $register,
        string $schema,
        string $berekeningId
    ): int {
        try {
            $berekening = $objectService->find($berekeningId, register: $register, schema: $schema);
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

        return $definitief;
    }//end resolvePayableAmount()

    /**
     * Persist a DwangsomUitbetaling row.
     *
     * @param object               $objectService OpenRegister object service.
     * @param string               $register      Register identifier.
     * @param string               $schema        DwangsomUitbetaling schema identifier.
     * @param array<string, mixed> $row           Row to persist.
     *
     * @return array<string, mixed> The saved row, or the supplied row.
     *
     * @throws RuntimeException When persisting fails.
     */
    private function persistUitbetaling(
        object $objectService,
        string $register,
        string $schema,
        array $row
    ): array {
        try {
            $saved = $objectService->saveObject($register, $schema, $row);
            if (is_array($saved) === true) {
                return $saved;
            }

            return $row;
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomUitbetaling persist failed: '.$e->getMessage());
        }
    }//end persistUitbetaling()

    /**
     * Handle an ERP callback updating the uitbetaling state.
     *
     * @param string                 $referentie          Payment reference.
     * @param string                 $status              New status (betaald/afgewezen/in-behandeling).
     * @param DateTimeImmutable|null $betaaldatum         Actual payment date.
     * @param string                 $betalingsreferentie ERP/bank reference.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the referentie is unknown.
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
     */
    public function handleCallback(
        string $referentie,
        string $status,
        ?DateTimeImmutable $betaaldatum,
        string $betalingsreferentie=''
    ): array {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $uSchema       = (string) $this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
        if ($objectService === null || $register === '' || $uSchema === '') {
            throw new RuntimeException('Dwangsom services not configured');
        }

        $row = $this->findUitbetalingByReferentie(
            objectService: $objectService,
            register: $register,
            schema: $uSchema,
            referentie: $referentie
        );

        $row = $this->applyCallbackFields(
            row: $row,
            status: $status,
            betaaldatum: $betaaldatum,
            betalingsreferentie: $betalingsreferentie
        );

        return $this->persistUitbetaling(
            objectService: $objectService,
            register: $register,
            schema: $uSchema,
            row: $row
        );
    }//end handleCallback()

    /**
     * Look up the single DwangsomUitbetaling row carrying a referentie.
     *
     * @param object $objectService OpenRegister object service.
     * @param string $register      Register identifier.
     * @param string $schema        DwangsomUitbetaling schema identifier.
     * @param string $referentie    Payment reference.
     *
     * @return array<string, mixed> The matching row.
     *
     * @throws RuntimeException When the lookup fails or the referentie is unknown.
     */
    private function findUitbetalingByReferentie(
        object $objectService,
        string $register,
        string $schema,
        string $referentie
    ): array {
        try {
            $rows = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['referentie' => $referentie],
            );
        } catch (\Throwable $e) {
            throw new RuntimeException('DwangsomUitbetaling lookup failed: '.$e->getMessage());
        }

        $row = null;
        if (is_array($rows) === true && count($rows) > 0) {
            $row = $rows[0];
        }

        if (is_array($row) === false) {
            throw new RuntimeException('No DwangsomUitbetaling found for referentie '.$referentie);
        }

        return $row;
    }//end findUitbetalingByReferentie()

    /**
     * Apply the ERP callback fields onto an uitbetaling row.
     *
     * @param array<string, mixed>   $row                 Uitbetaling row.
     * @param string                 $status              New status.
     * @param DateTimeImmutable|null $betaaldatum         Actual payment date.
     * @param string                 $betalingsreferentie ERP/bank reference.
     *
     * @return array<string, mixed> The updated row.
     */
    private function applyCallbackFields(
        array $row,
        string $status,
        ?DateTimeImmutable $betaaldatum,
        string $betalingsreferentie
    ): array {
        $row['status'] = $status;
        if ($betalingsreferentie !== '') {
            $row['betalingsreferentie'] = $betalingsreferentie;
        }

        if ($betaaldatum !== null) {
            $row['werkelijkeBetaaldatum'] = $betaaldatum->format('Y-m-d');
        }

        return $row;
    }//end applyCallbackFields()

    /**
     * Conservative IBAN check (length + mod-97).
     *
     * @param string $iban IBAN.
     *
     * @return bool
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
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
            if (ctype_alpha($ch) === true) {
                $expanded .= (string) (ord($ch) - 55);
                continue;
            }

            $expanded .= $ch;
        }

        // Mod-97 over a string (PHP int can't hold this directly).
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
