<?php
declare(strict_types=1);
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Static guard: ensures no frontend HTML file loads core UI libraries
 * (Layui, cdnjs, etc.) from an external URL at runtime.
 * All Layui assets must be served from /vendor/layui/ (local copy).
 */
class FrontendAssetPolicyTest extends TestCase
{
    /** Absolute path to the frontend directory inside the container. */
    private string $frontendDir;

    protected function setUp(): void
    {
        // /app/tests/api/../../frontend  →  /app/frontend  (mounted from ./frontend)
        $this->frontendDir = realpath(__DIR__ . '/../../frontend') ?: '';
    }

    /** @return array<string, array{string}> */
    private function htmlFiles(): array
    {
        if ($this->frontendDir === '') {
            $this->markTestSkipped('frontend directory not mounted at /app/frontend');
        }

        $files = array_merge(
            glob($this->frontendDir . '/*.html') ?: [],
            glob($this->frontendDir . '/pages/*.html') ?: []
        );

        $cases = [];
        foreach ($files as $path) {
            $cases[basename(dirname($path)) . '/' . basename($path)] = [$path];
        }
        return $cases;
    }

    /**
     * @dataProvider htmlFiles
     */
    public function testNoExternalLayuiCdnReference(string $htmlPath): void
    {
        $content = file_get_contents($htmlPath);
        $this->assertNotFalse($content, "Cannot read {$htmlPath}");

        // Match <link href="https?://..."> or <script src="https?://...">
        // that reference Layui or the cdnjs CDN
        preg_match_all(
            '/<(?:link|script)[^>]+(?:href|src)=["\']https?:\/\/[^"\']*(?:layui|cdnjs)[^"\']*["\'][^>]*>/i',
            $content,
            $matches
        );

        $violations = $matches[0];
        $this->assertEmpty(
            $violations,
            sprintf(
                "File %s references Layui from an external CDN — use /vendor/layui/ instead.\nViolating tags:\n  %s",
                basename($htmlPath),
                implode("\n  ", $violations)
            )
        );
    }

    /** Confirm all pages load Layui from the local vendor path. */
    public function testAllPagesReferenceLocalLayui(): void
    {
        if ($this->frontendDir === '') {
            $this->markTestSkipped('frontend directory not mounted at /app/frontend');
        }

        $htmlFiles = array_merge(
            glob($this->frontendDir . '/*.html') ?: [],
            glob($this->frontendDir . '/pages/*.html') ?: []
        );

        $this->assertNotEmpty($htmlFiles, 'No HTML files found under frontend/');

        foreach ($htmlFiles as $path) {
            $content = (string)file_get_contents($path);
            $name    = basename(dirname($path)) . '/' . basename($path);

            // login.html uses Layui but not the admin layout; all others do too
            $this->assertStringContainsString(
                '/vendor/layui/',
                $content,
                "{$name} must reference local Layui assets under /vendor/layui/"
            );
        }
    }
}
