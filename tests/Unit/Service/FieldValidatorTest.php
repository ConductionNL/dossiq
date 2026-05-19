<?php
/**
 * FieldValidator Tests
 *
 * @category Service
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\FieldValidator;
use PHPUnit\Framework\TestCase;

class FieldValidatorTest extends TestCase
{

    private FieldValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FieldValidator();
    }//end setUp()

    public function testValidateDateFormatWithValidDate(): void
    {
        $this->assertTrue($this->validator->validateDateFormat(value: '2024-01-15'));
    }//end testValidateDateFormatWithValidDate()

    public function testValidateDateFormatWithInvalidDate(): void
    {
        $this->assertFalse($this->validator->validateDateFormat(value: 'not-a-date'));
    }//end testValidateDateFormatWithInvalidDate()

    public function testValidateDateFormatRejectsImpossibleDate(): void
    {
        $this->assertFalse($this->validator->validateDateFormat(value: '2024-13-01'));
    }//end testValidateDateFormatRejectsImpossibleDate()

    public function testValidateDateFormatRejectsPartialDate(): void
    {
        $this->assertFalse($this->validator->validateDateFormat(value: '2024-01'));
    }//end testValidateDateFormatRejectsPartialDate()

    public function testValidateDateRangeWithValidRange(): void
    {
        $this->assertTrue($this->validator->validateDateRange(from: '2024-01-01', to: '2024-12-31'));
    }//end testValidateDateRangeWithValidRange()

    public function testValidateDateRangeWithEqualDates(): void
    {
        $this->assertTrue($this->validator->validateDateRange(from: '2024-06-01', to: '2024-06-01'));
    }//end testValidateDateRangeWithEqualDates()

    public function testValidateDateRangeWithInvertedRange(): void
    {
        $this->assertFalse($this->validator->validateDateRange(from: '2024-12-31', to: '2024-01-01'));
    }//end testValidateDateRangeWithInvertedRange()

    public function testValidateUrlWithValidUrl(): void
    {
        $this->assertTrue($this->validator->validateUrl(url: 'https://example.com/api/v1/resource'));
    }//end testValidateUrlWithValidUrl()

    public function testValidateUrlWithInvalidUrl(): void
    {
        $this->assertFalse($this->validator->validateUrl(url: 'not-a-url'));
    }//end testValidateUrlWithInvalidUrl()

    public function testValidateUrlWithEmptyString(): void
    {
        $this->assertFalse($this->validator->validateUrl(url: ''));
    }//end testValidateUrlWithEmptyString()

    public function testValidateUrlIsResourceEndpointWithUuidUrl(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertTrue(
            $this->validator->validateUrlIsResourceEndpoint(
                url: 'https://example.com/api/v1/zaken/'.$uuid
            )
        );
    }//end testValidateUrlIsResourceEndpointWithUuidUrl()

    public function testValidateUrlIsResourceEndpointWithCollectionUrl(): void
    {
        $this->assertFalse(
            $this->validator->validateUrlIsResourceEndpoint(
                url: 'https://example.com/api/v1/zaken'
            )
        );
    }//end testValidateUrlIsResourceEndpointWithCollectionUrl()

    public function testValidateUrlIsResourceEndpointWithInvalidUrl(): void
    {
        $this->assertFalse($this->validator->validateUrlIsResourceEndpoint(url: 'not-a-url'));
    }//end testValidateUrlIsResourceEndpointWithInvalidUrl()

    public function testValidateUrlIsResourceEndpointWithUuidAndTrailingSlash(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertTrue(
            $this->validator->validateUrlIsResourceEndpoint(
                url: 'https://example.com/api/v1/zaken/'.$uuid.'/'
            )
        );
    }//end testValidateUrlIsResourceEndpointWithUuidAndTrailingSlash()
}//end class
