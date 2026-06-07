<?php

/**
 * FieldValidator Unit Tests
 *
 * Tests for the stateless field-format validator extracted from ZgwRulesBase.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\FieldValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FieldValidator.
 */
class FieldValidatorTest extends TestCase
{
    /**
     * The validator under test.
     *
     * @var FieldValidator
     */
    private FieldValidator $validator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FieldValidator();
    }//end setUp()

    /**
     * Bare UUID input is returned verbatim.
     *
     * @return void
     */
    public function testExtractUuidReturnsBareUuid(): void
    {
        $uuid = '12345678-90ab-cdef-1234-567890abcdef';
        $this->assertSame($uuid, $this->validator->extractUuid($uuid));
    }//end testExtractUuidReturnsBareUuid()

    /**
     * A UUID embedded in a URL is extracted.
     *
     * @return void
     */
    public function testExtractUuidFromUrl(): void
    {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $url  = 'https://example.org/api/zaken/'.$uuid;
        $this->assertSame($uuid, $this->validator->extractUuid($url));
    }//end testExtractUuidFromUrl()

    /**
     * A string without a UUID yields null.
     *
     * @return void
     */
    public function testExtractUuidReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->validator->extractUuid('https://example.org/api/zaken'));
    }//end testExtractUuidReturnsNullWhenAbsent()

    /**
     * isUuid distinguishes bare UUIDs from URLs and garbage.
     *
     * @return void
     */
    public function testIsUuid(): void
    {
        $this->assertTrue($this->validator->isUuid('12345678-90ab-cdef-1234-567890abcdef'));
        $this->assertFalse($this->validator->isUuid('https://example.org/zaken/12345678-90ab-cdef-1234-567890abcdef'));
        $this->assertFalse($this->validator->isUuid('not-a-uuid'));
    }//end testIsUuid()

    /**
     * A resource URL ending in a UUID segment is valid.
     *
     * @return void
     */
    public function testIsValidUrlAcceptsResourceUrl(): void
    {
        $this->assertTrue(
            $this->validator->isValidUrl('https://example.org/api/zaken/12345678-90ab-cdef-1234-567890abcdef')
        );
    }//end testIsValidUrlAcceptsResourceUrl()

    /**
     * Collection endpoints, non-URLs and trailing garbage are rejected.
     *
     * @return void
     */
    public function testIsValidUrlRejectsNonResourceUrls(): void
    {
        $this->assertFalse($this->validator->isValidUrl('https://example.org/api/zaken'));
        $this->assertFalse($this->validator->isValidUrl('not a url'));
        $this->assertFalse(
            $this->validator->isValidUrl('https://example.org/api/zaken/12345678-90ab-cdef-1234-567890abcdef/extra')
        );
    }//end testIsValidUrlRejectsNonResourceUrls()

    /**
     * Real calendar dates pass; malformed or impossible dates fail.
     *
     * @return void
     */
    public function testIsValidDate(): void
    {
        $this->assertTrue($this->validator->isValidDate('2026-06-04'));
        $this->assertFalse($this->validator->isValidDate('2026-02-30'));
        $this->assertFalse($this->validator->isValidDate('2026-6-4'));
        $this->assertFalse($this->validator->isValidDate('04-06-2026'));
        $this->assertFalse($this->validator->isValidDate('not-a-date'));
    }//end testIsValidDate()
}//end class
