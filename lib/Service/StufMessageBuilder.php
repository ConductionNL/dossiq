<?php

/**
 * Procest StUF Message Builder
 *
 * Service for constructing the OUTBOUND StUF-ZKN kennisgevingen and vragen
 * procest sends toward a legacy zaaksysteem: buildLk01CreeerZaak /
 * buildLk02ActualiseerZaak / buildLv01GeefDetails / buildDu01GenereerZaakId /
 * buildDu01VrijBericht — string-concatenated, `zkn:`-namespaced StUF 0310
 * envelopes wrapped with a WSSE UsernameToken header. The WSSE password is
 * resolved from the vault at send time and is never logged or persisted.
 * Folded in from the pipelinq StufEnvelopeBuilder during the StUF-ZKN
 * outbound-gateway migration.
 *
 * The INBOUND direction — the responses procest returns as a StUF receiver —
 * lives in {@see \OCA\Procest\Service\Stuf\StufResponseBuilder}. One builder
 * owning both directions exposed fourteen public methods with two disjoint
 * caller sets and two different XML styles.
 *
 * The StUF namespace constants stay here: they are the canonical home that
 * StufMessageParser, StufResponseBuilder and StufController all read from.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/stuf-integration/spec.md
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-envelope-construction
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Procest\Service\Stuf\PayloadTooLargeException;
use OCA\Procest\Service\Stuf\StufVaultService;
use OCA\Procest\Service\Stuf\VrijBerichtNotRegisteredException;
use OCA\Procest\Service\Stuf\ZaaktypeNotMappedException;
use Psr\Log\LoggerInterface;

/**
 * Service for constructing the outbound StUF-ZKN request envelopes.
 *
 * @psalm-suppress UnusedClass
 */
class StufMessageBuilder
{
    /**
     * StUF base namespace.
     */
    public const NS_STUF = 'http://www.egem.nl/StUF/StUF0301';

    /**
     * StUF-ZKN namespace.
     */
    public const NS_ZKN = 'http://www.egem.nl/StUF/sector/zkn/0310';

    /**
     * StUF-BG namespace.
     */
    public const NS_BG = 'http://www.egem.nl/StUF/sector/bg/0310';

    /**
     * SOAP envelope namespace (alias of NS_SOAPENV; kept for inbound callers).
     */
    public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * SOAP envelope namespace (outbound builder + parser).
     */
    public const NS_SOAPENV = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * WS-Security extension namespace (WSSE UsernameToken header).
     */
    public const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    /**
     * XML Schema Instance namespace.
     */
    public const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * Default pre-base64 document payload ceiling (25 MiB).
     */
    public const PAYLOAD_LIMIT_BYTES = (25 * 1024 * 1024);

    /**
     * NoValue attribute values.
     *
     * @var array<string, string>
     */
    public const NO_VALUE_TYPES = [
        'geenWaarde'          => 'geenWaarde',
        'waardeOnbekend'      => 'waardeOnbekend',
        'nietOndersteund'     => 'nietOndersteund',
        'vastgesteldOnbekend' => 'vastgesteldOnbekend',
    ];

    /**
     * Constructor.
     *
     * @param LoggerInterface  $logger The logger instance.
     * @param StufVaultService $vault  The vault adapter (resolves WSSE credential references for outbound builds).
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly StufVaultService $vault,
    ) {
    }//end __construct()

    /**
     * Build an Lk01 creeerZaak envelope from a case and the target endpoint (outbound).
     *
     * @param array       $case     The procest case as a plain array (id, type, omschrijving,
     *                              startdatum, einddatum, betrokkenen[], documenten[]).
     * @param array       $endpoint The StufEndpoint object (array).
     * @param string|null $zaakId   Optional pre-allocated zaak identificatie.
     * @param array       $opts     Options: includeDocuments (bool), payloadLimitBytes (int).
     *
     * @return string The signed envelope XML.
     *
     * @throws ZaaktypeNotMappedException When case.type has no mapping on the endpoint.
     * @throws PayloadTooLargeException   When the attached documents exceed the configured ceiling.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-envelope-construction
     */
    public function buildLk01CreeerZaak(
        array $case,
        array $endpoint,
        ?string $zaakId=null,
        array $opts=[]
    ): string {
        $type    = (string) ($case['type'] ?? '');
        $mapping = $endpoint['zaaktypeMappings'] ?? [];
        if (is_array(value: $mapping) === false || array_key_exists(key: $type, array: $mapping) === false) {
            throw new ZaaktypeNotMappedException(
                message: sprintf('No zaaktype mapping for case.type "%s" on endpoint "%s"', $type, (string) ($endpoint['id'] ?? ''))
            );
        }

        $omschrijving = (string) $mapping[$type];
        $includeDocs  = (bool) ($opts['includeDocuments'] ?? false);
        $payloadLimit = (int) ($opts['payloadLimitBytes'] ?? self::PAYLOAD_LIMIT_BYTES);

        $documents = [];
        if ($includeDocs === true) {
            $documents = $this->assertPayloadFitsAndEncode(
                documents: ($case['documenten'] ?? []),
                limitBytes: $payloadLimit
            );
        }

        $referentienummer = $this->generateReferentienummer();
        $tijdstipBericht  = $this->currentTimestampStuf();

        $stuurgegevens = $this->buildOutboundStuurgegevens(
            berichtCode: 'Lk01',
            endpoint: $endpoint,
            entiteittype: 'ZAK',
            functie: 'creeerZaak',
            referentienummer: $referentienummer,
            tijdstipBericht: $tijdstipBericht
        );

        $body = $this->renderZakLk01(
            stuurgegevens: $stuurgegevens,
            zaakId: $zaakId,
            zaaktypeOmschrijving: $omschrijving,
            case: $case,
            documents: $documents
        );

        $envelope = $this->wrapEnvelope(bodyXml: $body, endpoint: $endpoint);
        $this->logger->debug(
            message: 'StUF Lk01 envelope built',
            context: [
                'endpoint'         => ($endpoint['id'] ?? ''),
                'referentienummer' => $referentienummer,
                'snippet'          => substr(string: $envelope, offset: 0, length: 500),
            ]
        );

        return $envelope;
    }//end buildLk01CreeerZaak()

    /**
     * Build an Lk02 actualiseerZaak envelope (outbound).
     *
     * @param array $case     The procest case (updated fields).
     * @param array $mapping  The existing ZaaksysteemMapping.
     * @param array $endpoint The StufEndpoint.
     *
     * @return string The envelope XML.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-envelope-construction
     */
    public function buildLk02ActualiseerZaak(array $case, array $mapping, array $endpoint): string
    {
        $stuurgegevens = $this->buildOutboundStuurgegevens(
            berichtCode: 'Lk02',
            endpoint: $endpoint,
            entiteittype: 'ZAK',
            functie: 'actualiseerZaak',
            referentienummer: $this->generateReferentienummer(),
            tijdstipBericht: $this->currentTimestampStuf()
        );

        $zaakId = (string) ($mapping['externIdentificatie'] ?? '');
        $body   = '<zkn:zakLk02>'.$stuurgegevens
            .'<zkn:object stuf:entiteittype="ZAK" stuf:verwerkingssoort="W">'
            .'<zkn:identificatie>'.$this->escape(value: $zaakId).'</zkn:identificatie>'
            .$this->renderZaakMutatieElements(case: $case)
            .'</zkn:object>'
            .'</zkn:zakLk02>';

        return $this->wrapEnvelope(bodyXml: $body, endpoint: $endpoint);
    }//end buildLk02ActualiseerZaak()

    /**
     * Build an Lv01 geefZaakDetails envelope (outbound).
     *
     * @param string $zaakId            The zaak identificatie to query.
     * @param array  $endpoint          The StufEndpoint.
     * @param array  $gewensteElementen The list of zkn element names to request.
     *
     * @return string The envelope XML.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-synchronous-zaak-query
     */
    public function buildLv01GeefDetails(string $zaakId, array $endpoint, array $gewensteElementen=[]): string
    {
        $stuurgegevens = $this->buildOutboundStuurgegevens(
            berichtCode: 'Lv01',
            endpoint: $endpoint,
            entiteittype: 'ZAK',
            functie: 'geefZaakDetails',
            referentienummer: $this->generateReferentienummer(),
            tijdstipBericht: $this->currentTimestampStuf()
        );

        $scope = '';
        foreach ($gewensteElementen as $element) {
            $scope .= '<zkn:'.$this->escape(value: (string) $element).' />';
        }

        $body = '<zkn:zakLv01>'.$stuurgegevens
            .'<zkn:gelijk stuf:entiteittype="ZAK">'
            .'<zkn:identificatie>'.$this->escape(value: $zaakId).'</zkn:identificatie>'
            .'</zkn:gelijk>'
            .'<zkn:scope><zkn:object stuf:entiteittype="ZAK">'.$scope.'</zkn:object></zkn:scope>'
            .'</zkn:zakLv01>';

        return $this->wrapEnvelope(bodyXml: $body, endpoint: $endpoint);
    }//end buildLv01GeefDetails()

    /**
     * Build a Du01 genereerZaakIdentificatie envelope (pre-allocation, outbound).
     *
     * @param array $endpoint The StufEndpoint.
     *
     * @return string The envelope XML.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-zaak-identificatie-allocation
     */
    public function buildDu01GenereerZaakId(array $endpoint): string
    {
        $stuurgegevens = $this->buildOutboundStuurgegevens(
            berichtCode: 'Du01',
            endpoint: $endpoint,
            entiteittype: 'ZAK',
            functie: 'genereerZaakIdentificatie',
            referentienummer: $this->generateReferentienummer(),
            tijdstipBericht: $this->currentTimestampStuf()
        );

        $body = '<zkn:genereerZaakIdentificatie_Du01>'.$stuurgegevens.'</zkn:genereerZaakIdentificatie_Du01>';
        return $this->wrapEnvelope(bodyXml: $body, endpoint: $endpoint);
    }//end buildDu01GenereerZaakId()

    /**
     * Build a Du01 envelope from a registered vrijBericht template (outbound).
     *
     * @param string $name     The template name.
     * @param array  $payload  The payload values.
     * @param array  $endpoint The StufEndpoint (template registered under `vrijeBerichtenTemplates`).
     *
     * @return string The envelope XML.
     *
     * @throws VrijBerichtNotRegisteredException If the template is not registered.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-free-message-templates
     */
    public function buildDu01VrijBericht(string $name, array $payload, array $endpoint): string
    {
        $templates = ($endpoint['vrijeBerichtenTemplates'] ?? []);
        $template  = null;
        foreach ($templates as $candidate) {
            if (($candidate['naam'] ?? '') === $name) {
                $template = $candidate;
                break;
            }
        }

        if ($template === null) {
            throw new VrijBerichtNotRegisteredException(
                message: sprintf('vrijBericht "%s" not registered on endpoint "%s"', $name, (string) ($endpoint['id'] ?? ''))
            );
        }

        foreach (($template['verplichteVelden'] ?? []) as $verplicht) {
            if (array_key_exists(key: $verplicht, array: $payload) === false) {
                throw new VrijBerichtNotRegisteredException(
                    message: sprintf('vrijBericht "%s" mist verplicht veld "%s"', $name, (string) $verplicht)
                );
            }
        }

        $stuurgegevens = $this->buildOutboundStuurgegevens(
            berichtCode: 'Du01',
            endpoint: $endpoint,
            entiteittype: 'ZAK',
            functie: $name,
            referentienummer: $this->generateReferentienummer(),
            tijdstipBericht: $this->currentTimestampStuf()
        );

        $payloadXml = '';
        foreach ($payload as $veld => $waarde) {
            $veldNaam    = $this->escape(value: (string) $veld);
            $payloadXml .= '<zkn:'.$veldNaam.'>'.$this->escape(value: (string) $waarde).'</zkn:'.$veldNaam.'>';
        }

        $body = '<zkn:'.$this->escape(value: $name).'_Du01>'.$stuurgegevens
            .'<zkn:parameters>'.$payloadXml.'</zkn:parameters>'
            .'</zkn:'.$this->escape(value: $name).'_Du01>';

        return $this->wrapEnvelope(bodyXml: $body, endpoint: $endpoint);
    }//end buildDu01VrijBericht()

    /**
     * Build the outbound StUF stuurgegevens header XML (zkn-namespaced, endpoint-driven).
     *
     * @param string $berichtCode      The bericht-code (Lk01, Lk02, Lv01, Du01).
     * @param array  $endpoint         The StufEndpoint array.
     * @param string $entiteittype     The entiteittype (ZAK).
     * @param string $functie          The functie (creeerZaak, ...).
     * @param string $referentienummer The unique referentienummer.
     * @param string $tijdstipBericht  The yyyyMMddHHmmssSSS timestamp.
     *
     * @return string The stuurgegevens XML snippet.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-envelope-construction
     */
    public function buildOutboundStuurgegevens(
        string $berichtCode,
        array $endpoint,
        string $entiteittype,
        string $functie,
        string $referentienummer,
        string $tijdstipBericht
    ): string {
        return '<zkn:stuurgegevens>'
            .'<stuf:berichtcode>'.$this->escape(value: $berichtCode).'</stuf:berichtcode>'
            .'<stuf:zender>'
            .'<stuf:organisatie>'.$this->escape(value: (string) ($endpoint['zenderOrganisatie'] ?? '')).'</stuf:organisatie>'
            .'<stuf:applicatie>'.$this->escape(value: (string) ($endpoint['zenderApplicatie'] ?? '')).'</stuf:applicatie>'
            .'</stuf:zender>'
            .'<stuf:ontvanger>'
            .'<stuf:organisatie>'.$this->escape(value: (string) ($endpoint['ontvangerOrganisatie'] ?? '')).'</stuf:organisatie>'
            .'<stuf:applicatie>'.$this->escape(value: (string) ($endpoint['ontvangerApplicatie'] ?? '')).'</stuf:applicatie>'
            .'<stuf:gebruiker>'.$this->escape(value: (string) ($endpoint['ontvangerGebruiker'] ?? '')).'</stuf:gebruiker>'
            .'</stuf:ontvanger>'
            .'<stuf:referentienummer>'.$this->escape(value: $referentienummer).'</stuf:referentienummer>'
            .'<stuf:tijdstipBericht>'.$this->escape(value: $tijdstipBericht).'</stuf:tijdstipBericht>'
            .'<stuf:entiteittype>'.$this->escape(value: $entiteittype).'</stuf:entiteittype>'
            .'<stuf:functie>'.$this->escape(value: $functie).'</stuf:functie>'
            .'</zkn:stuurgegevens>';
    }//end buildOutboundStuurgegevens()

    /**
     * Generate a fresh referentienummer (ULID-like).
     *
     * Uses a Crockford-base32 encoding of the current millisecond timestamp
     * plus 80 bits of randomness. Compatible with libraries that consume
     * 26-character ULIDs but does not require an external dependency.
     *
     * @return string A 26-character uppercase identifier.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-envelope-construction
     */
    public function generateReferentienummer(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $timeMs   = (int) round((microtime(as_float: true) * 1000));
        $timePart = '';
        for ($i = 9; $i >= 0; $i--) {
            $timePart = $alphabet[($timeMs & 0x1F)].$timePart;
            $timeMs   = ($timeMs >> 5);
        }

        $randomPart = '';
        for ($i = 0; $i < 16; $i++) {
            $randomPart .= $alphabet[(random_int(min: 0, max: 31))];
        }

        return $timePart.$randomPart;
    }//end generateReferentienummer()

    /**
     * Generate a tijdstipBericht in StUF format (yyyyMMddHHmmssSSS) in Europe/Amsterdam.
     *
     * @return string The 17-character timestamp.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-envelope-construction
     */
    public function currentTimestampStuf(): string
    {
        $now    = new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'Europe/Amsterdam'));
        $millis = (int) substr(string: $now->format(format: 'u'), offset: 0, length: 3);
        return $now->format(format: 'YmdHis').str_pad(string: (string) $millis, length: 3, pad_string: '0', pad_type: STR_PAD_LEFT);
    }//end currentTimestampStuf()

    /**
     * Assert that the total uncompressed payload (pre-base64) fits within the limit, return encoded entries.
     *
     * @param array $documents  The documents [{name, mime, bytes}, ...].
     * @param int   $limitBytes The pre-base64 limit.
     *
     * @return array<int,array{name:string,mime:string,base64:string}> The encoded documents.
     *
     * @throws PayloadTooLargeException If the limit is exceeded.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-document-payload-limit
     */
    private function assertPayloadFitsAndEncode(array $documents, int $limitBytes): array
    {
        $total   = 0;
        $encoded = [];
        foreach ($documents as $doc) {
            $bytes  = (string) ($doc['bytes'] ?? '');
            $size   = strlen(string: $bytes);
            $total += $size;
            if ($total > $limitBytes) {
                throw new PayloadTooLargeException(
                    message: sprintf('Pre-base64 payload %d bytes exceeds limit %d bytes', $total, $limitBytes)
                );
            }

            $encoded[] = [
                'name'   => (string) ($doc['name'] ?? 'document'),
                'mime'   => (string) ($doc['mime'] ?? 'application/octet-stream'),
                'base64' => base64_encode(string: $bytes),
            ];
        }

        return $encoded;
    }//end assertPayloadFitsAndEncode()

    /**
     * Render the zkn:zakLk01 body element.
     *
     * @param string      $stuurgegevens        The stuurgegevens XML.
     * @param string|null $zaakId               Pre-allocated zaak ID (when applicable).
     * @param string      $zaaktypeOmschrijving The mapped zaaktype omschrijving.
     * @param array       $case                 The case as array.
     * @param array       $documents            Encoded documents.
     *
     * @return string The XML body fragment.
     */
    private function renderZakLk01(
        string $stuurgegevens,
        ?string $zaakId,
        string $zaaktypeOmschrijving,
        array $case,
        array $documents
    ): string {
        $identificatie = '';
        if ($zaakId !== null && $zaakId !== '') {
            $identificatie = '<zkn:identificatie>'.$this->escape(value: $zaakId).'</zkn:identificatie>';
        }

        $omschrijving = $this->escape(value: (string) ($case['omschrijving'] ?? ''));
        $startdatum   = $this->escape(value: (string) ($case['startdatum'] ?? ''));

        $betrokkenen = '';
        foreach (($case['betrokkenen'] ?? []) as $bet) {
            $bsn  = (string) ($bet['bsn'] ?? '');
            $rol  = $this->escape(value: (string) ($bet['rol'] ?? 'heeftAlsInitiator'));
            $body = '<zkn:gerelateerde stuf:entiteittype="NPS">'
                .'<bg:inp.bsn>'.$this->escape(value: $bsn).'</bg:inp.bsn>'
                .'</zkn:gerelateerde>';

            $betrokkenen .= '<zkn:'.$rol.'>'.$body.'</zkn:'.$rol.'>';
        }

        $documentenXml = '';
        foreach ($documents as $doc) {
            $documentenXml .= '<zkn:heeftRelevant>'
                .'<zkn:gerelateerde stuf:entiteittype="EDC">'
                .'<stuf:bestandsnaam>'.$this->escape(value: $doc['name']).'</stuf:bestandsnaam>'
                .'<stuf:formaat>'.$this->escape(value: $doc['mime']).'</stuf:formaat>'
                .'<stuf:bestandsinhoud>'.$doc['base64'].'</stuf:bestandsinhoud>'
                .'</zkn:gerelateerde>'
                .'</zkn:heeftRelevant>';
        }

        return '<zkn:zakLk01>'.$stuurgegevens
            .'<zkn:object stuf:entiteittype="ZAK" stuf:verwerkingssoort="T">'
            .$identificatie
            .'<zkn:omschrijving>'.$omschrijving.'</zkn:omschrijving>'
            .'<zkn:startdatum>'.$startdatum.'</zkn:startdatum>'
            .'<zkn:zaaktype>'
            .'<zkn:omschrijving>'.$this->escape(value: $zaaktypeOmschrijving).'</zkn:omschrijving>'
            .'</zkn:zaaktype>'
            .$betrokkenen
            .$documentenXml
            .'</zkn:object>'
            .'</zkn:zakLk01>';
    }//end renderZakLk01()

    /**
     * Render Lk02 mutation elements (sparse update of allowed fields).
     *
     * @param array $case The case with updated fields.
     *
     * @return string The XML mutation fragment.
     */
    private function renderZaakMutatieElements(array $case): string
    {
        $out = '';
        foreach (['omschrijving', 'einddatum', 'resultaattoelichting'] as $field) {
            if (array_key_exists(key: $field, array: $case) === true && $case[$field] !== null) {
                $out .= '<zkn:'.$field.'>'.$this->escape(value: (string) $case[$field]).'</zkn:'.$field.'>';
            }
        }

        return $out;
    }//end renderZaakMutatieElements()

    /**
     * Wrap a body fragment in a complete SOAP envelope with WSSE security header (outbound).
     *
     * Loads the WSSE password from vault at send time (here only the reference
     * lookup; the value is never logged or persisted on the message).
     *
     * @param string $bodyXml  The pre-built body XML (must be one root element).
     * @param array  $endpoint The StufEndpoint with authenticatie config.
     *
     * @return string The full envelope XML.
     *
     * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-secure-credential-handling
     */
    private function wrapEnvelope(string $bodyXml, array $endpoint): string
    {
        $auth        = ($endpoint['authenticatie'] ?? []);
        $username    = $this->escape(value: (string) ($auth['gebruikersnaam'] ?? ''));
        $passwordRef = (string) ($auth['wachtwoordKluisRef'] ?? '');
        $password    = $this->escape(value: $this->vault->resolveSecret(reference: $passwordRef));

        $security = '<wsse:Security>'
            .'<wsse:UsernameToken>'
            .'<wsse:Username>'.$username.'</wsse:Username>'
            .'<wsse:Password>'.$password.'</wsse:Password>'
            .'</wsse:UsernameToken>'
            .'</wsse:Security>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope'
            .' xmlns:soapenv="'.self::NS_SOAPENV.'"'
            .' xmlns:stuf="'.self::NS_STUF.'"'
            .' xmlns:zkn="'.self::NS_ZKN.'"'
            .' xmlns:bg="'.self::NS_BG.'"'
            .' xmlns:wsse="'.self::NS_WSSE.'">'
            .'<soapenv:Header>'.$security.'</soapenv:Header>'
            .'<soapenv:Body>'.$bodyXml.'</soapenv:Body>'
            .'</soapenv:Envelope>';
    }//end wrapEnvelope()

    /**
     * XML-escape a value.
     *
     * @param string $value The value to escape.
     *
     * @return string The escaped value.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars(string: $value, flags: (ENT_XML1 | ENT_QUOTES), encoding: 'UTF-8');
    }//end escape()
}//end class
