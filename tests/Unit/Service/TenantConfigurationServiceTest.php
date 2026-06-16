<?php

/**
 * TenantConfigurationService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-08-configuration-branding/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\TenantConfigurationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantConfigurationService
 */
class TenantConfigurationServiceTest extends TestCase
{
    private TenantConfigurationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TenantConfigurationService(
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testIsHexColorAcceptsSixDigit(): void
    {
        $this->assertTrue($this->svc->isHexColor('#abcdef'));
        $this->assertTrue($this->svc->isHexColor('#000000'));
    }

    public function testIsHexColorRejectsThreeDigit(): void
    {
        $this->assertFalse($this->svc->isHexColor('#abc'));
    }

    public function testIsHexColorRejectsInjection(): void
    {
        $this->assertFalse($this->svc->isHexColor('red'));
        $this->assertFalse($this->svc->isHexColor('#abcdef; XSS'));
    }

    public function testSanitiseBrandingRejectsBadHex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->sanitiseBranding(['primaryColor' => 'not-a-hex']);
    }

    public function testSanitiseBrandingKeepsValidFields(): void
    {
        $out = $this->svc->sanitiseBranding([
            'primaryColor' => '#1a2b3c',
            'logo'         => 'https://x/y.png',
        ]);
        $this->assertSame('#1a2b3c', $out['primaryColor']);
        $this->assertSame('https://x/y.png', $out['logo']);
    }

    public function testSanitiseCustomCssDropsImports(): void
    {
        $this->assertSame('', $this->svc->sanitiseCustomCss('@import "evil";'));
    }

    public function testSanitiseCustomCssDropsUrlExpressions(): void
    {
        $this->assertSame('', $this->svc->sanitiseCustomCss('background: url(evil.gif);'));
        $this->assertSame('', $this->svc->sanitiseCustomCss('width: expression(alert(1));'));
    }

    public function testSanitiseCustomCssKeepsWhitelistedProperties(): void
    {
        $out = $this->svc->sanitiseCustomCss('color: #abc; padding: 4px;');
        $this->assertSame('color: #abc; padding: 4px;', $out);
    }

    public function testSanitiseCustomCssDropsNonWhitelistedProperty(): void
    {
        $out = $this->svc->sanitiseCustomCss('color: red; behavior: url(htc);');
        $this->assertSame('', $out);
    }

    public function testUpdateLocaleRejectsBadLocale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->updateLocale('t-1', ['locale' => 'xx_YY']);
    }

    public function testUpdateLocaleRejectsBadTimezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->updateLocale('t-1', ['timezone' => 'Atlantis/Sea']);
    }

    public function testUpdateLocaleRejectsBadCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->updateLocale('t-1', ['currency' => 'euros']);
    }

    public function testValidateLogoUploadRejectsTooBig(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->validateLogoUpload('image/png', TenantConfigurationService::LOGO_MAX_BYTES + 1);
    }

    public function testValidateLogoUploadRejectsBadMime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->validateLogoUpload('application/pdf', 1024);
    }

    public function testValidateLogoUploadAccepts(): void
    {
        $this->svc->validateLogoUpload('image/png', 1024);
        $this->expectNotToPerformAssertions();
    }

    public function testGetThemingTokensExtractsCssVariables(): void
    {
        $tokens = $this->svc->getThemingTokens([
            'branding' => [
                'primaryColor'   => '#abcdef',
                'secondaryColor' => '#123456',
                'fontFamily'     => 'Inter, sans-serif',
            ],
        ]);
        $this->assertSame('#abcdef', $tokens['--nc-color-primary']);
        $this->assertSame('#123456', $tokens['--procest-color-secondary']);
        $this->assertStringContainsString('Inter', $tokens['--procest-font-family']);
    }
}
