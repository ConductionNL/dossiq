<?php

/**
 * Test stub for OpenRegister's Verwerkingsactiviteit entity.
 *
 * Minimal surface needed by procest unit tests: the catalogue seed repair
 * step (SeedVerwerkingsactiviteiten) sets the descriptive AVG art. 30
 * fields and reads code/status. Mirrors the real OR entity's setters and
 * its rechtsgrond vocabulary so vocabulary assertions stay honest.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub of OpenRegister's Verwerkingsactiviteit entity for unit tests.
 *
 * @method void        setCode(?string $code)
 * @method void        setNaam(?string $naam)
 * @method void        setBeschrijving(?string $beschrijving)
 * @method void        setDoelbinding(?string $doelbinding)
 * @method void        setRechtsgrond(?string $rechtsgrond)
 * @method void        setBewaartermijn(?string $bewaartermijn)
 * @method void        setStatus(?string $status)
 * @method void        setCategorieenBetrokkenen(?array $categorieen)
 * @method void        setCategorieenPersoonsgegevens(?array $categorieen)
 * @method void        setOntvangers(?array $ontvangers)
 * @method string|null getCode()
 * @method string|null getNaam()
 * @method string|null getDoelbinding()
 * @method string|null getRechtsgrond()
 * @method string|null getStatus()
 */
class Verwerkingsactiviteit
{
    /**
     * Article 6 GDPR legal-basis vocabulary (mirrors OR).
     *
     * @var array<int, string>
     */
    public const RECHTSGROND_VOCABULARY = [
        'toestemming',
        'overeenkomst',
        'wettelijke_verplichting',
        'vitaal_belang',
        'publieke_taak',
        'gerechtvaardigd_belang',
    ];

    /**
     * Lifecycle status vocabulary (mirrors OR).
     *
     * @var array<int, string>
     */
    public const STATUS_VOCABULARY = ['concept', 'published', 'archived'];

    /**
     * Captured field values (setter sink for assertions).
     *
     * @var array<string, mixed>
     */
    private array $fields = [];

    /**
     * Magic setter/getter sink recording every set*() and answering get*().
     *
     * @param string            $name Method name.
     * @param array<int, mixed> $args Arguments.
     *
     * @return mixed
     */
    public function __call(string $name, array $args)
    {
        if (str_starts_with($name, 'set') === true) {
            $this->fields[lcfirst(substr($name, 3))] = ($args[0] ?? null);
            return null;
        }

        if (str_starts_with($name, 'get') === true) {
            return ($this->fields[lcfirst(substr($name, 3))] ?? null);
        }

        throw new \BadMethodCallException($name);

    }//end __call()

    /**
     * All captured fields (test accessor).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;

    }//end toArray()
}//end class
