<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Service\InputSanitizer;
use PHPUnit\Framework\TestCase;

final class InputSanitizerTest extends TestCase
{
    private InputSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new InputSanitizer();
    }

    public function testSanitizeStringStripsHtmlTags(): void
    {
        $this->assertSame('hola mundo', $this->sanitizer->sanitizeString('<b>hola</b> mundo'));
        // strip_tags elimina las etiquetas; el texto interior queda como texto plano (inofensivo)
        $this->assertSame('alert(1)', $this->sanitizer->sanitizeString('<script>alert(1)</script>'));
        $this->assertStringNotContainsString('<', $this->sanitizer->sanitizeString('<img src=x onerror=alert(1)>'));
    }

    public function testSanitizeStringNormalizesWhitespaceAndTrims(): void
    {
        $this->assertSame('a b', $this->sanitizer->sanitizeString("  a \t  b  "));
    }

    public function testSanitizeStringRemovesControlCharacters(): void
    {
        $this->assertSame('abc', $this->sanitizer->sanitizeString("a\x00\x1Fb\x7Fc"));
    }

    public function testSanitizeEmailLowercasesAndTrims(): void
    {
        $this->assertSame('user@example.com', $this->sanitizer->sanitizeEmail('  User@Example.COM  '));
    }

    public function testSanitizeEmailRemovesInternalSpaces(): void
    {
        $this->assertSame('user@example.com', $this->sanitizer->sanitizeEmail('us er@exam ple.com'));
    }

    public function testSanitizeEmailEmptyStaysEmpty(): void
    {
        $this->assertSame('', $this->sanitizer->sanitizeEmail('   '));
    }
}
