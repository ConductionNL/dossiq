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
use OCA\Procest\Service\Tenant\TenantBrandingSanitiser;
use OCA\Procest\Service\TenantConfigurationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantConfigurationService
 * @covers \OCA\Procest\Service\Tenant\TenantBrandingSanitiser
 */
class TenantConfigurationServiceTest extends TestCase {
	private TenantConfigurationService $svc;

	protected function setUp(): void {
		parent::setUp();
		// A REAL sanitiser, not a mock: these tests assert the production
		// fail-closed branding rules, so a mocked collaborator would only
		// replay its own canned answer.
		$this->svc = new TenantConfigurationService(
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			sanitiser: new TenantBrandingSanitiser(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	public function testIsHexColorAcceptsSixDigit(): void {
		$this->assertTrue($this->svc->isHexColor('#abcdef'));
		$this->assertTrue($this->svc->isHexColor('#000000'));
	}

	public function testIsHexColorRejectsThreeDigit(): void {
		$this->assertFalse($this->svc->isHexColor('#abc'));
	}

	public function testIsHexColorRejectsInjection(): void {
		$this->assertFalse($this->svc->isHexColor('red'));
		$this->assertFalse($this->svc->isHexColor('#abcdef; XSS'));
	}

	public function testSanitiseBrandingRejectsBadHex(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->svc->sanitiseBranding(['primaryColor' => 'not-a-hex']);
	}

	public function testSanitiseBrandingKeepsValidFields(): void {
		$out = $this->svc->sanitiseBranding([
			'primaryColor' => '#1a2b3c',
			'logo' => 'https://x/y.png',
		]);
		$this->assertSame('#1a2b3c', $out['primaryColor']);
		$this->assertSame('https://x/y.png', $out['logo']);
	}

	public function testSanitiseCustomCssDropsImports(): void {
		$this->assertSame('', $this->svc->sanitiseCustomCss('@import "evil";'));
	}

	public function testSanitiseCustomCssDropsUrlExpressions(): void {
		$this->assertSame('', $this->svc->sanitiseCustomCss('background: url(evil.gif);'));
		$this->assertSame('', $this->svc->sanitiseCustomCss('width: expression(alert(1));'));
	}

	public function testSanitiseCustomCssKeepsWhitelistedProperties(): void {
		$out = $this->svc->sanitiseCustomCss('color: #abc; padding: 4px;');
		$this->assertSame('color: #abc; padding: 4px;', $out);
	}

	public function testSanitiseCustomCssDropsNonWhitelistedProperty(): void {
		$out = $this->svc->sanitiseCustomCss('color: red; behavior: url(htc);');
		$this->assertSame('', $out);
	}

	// The three testUpdateLocaleRejects* cases were removed with
	// TenantConfigurationService::updateLocale() and ::updateBranding(). Both
	// were per-tenant configuration writers with no caller and no route, so
	// their input validation never guarded a real request. The allow-lists
	// they checked (ALLOWED_LOCALES, ALLOWED_TIMEZONES, the ISO-4217 pattern)
	// are still on the class for the writer that eventually arrives.

	public function testValidateLogoUploadRejectsTooBig(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->svc->validateLogoUpload('image/png', TenantConfigurationService::LOGO_MAX_BYTES + 1);
	}

	public function testValidateLogoUploadRejectsBadMime(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->svc->validateLogoUpload('application/pdf', 1024);
	}

	public function testValidateLogoUploadAccepts(): void {
		$this->svc->validateLogoUpload('image/png', 1024);
		$this->expectNotToPerformAssertions();
	}

	public function testGetThemingTokensExtractsCssVariables(): void {
		$tokens = $this->svc->getThemingTokens([
			'branding' => [
				'primaryColor' => '#abcdef',
				'secondaryColor' => '#123456',
				'fontFamily' => 'Inter, sans-serif',
			],
		]);
		$this->assertSame('#abcdef', $tokens['--nc-color-primary']);
		$this->assertSame('#123456', $tokens['--procest-color-secondary']);
		$this->assertStringContainsString('Inter', $tokens['--procest-font-family']);
	}
}
