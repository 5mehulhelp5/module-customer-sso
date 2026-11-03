<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\View;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class HyvaLayoutTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const LAYOUT_DIR = self::ROOT . '/view/frontend/layout';
    private const TEMPLATE_DIR = self::ROOT . '/view/frontend/templates';
    private const BLOCK = 'dmlab.customer.sso.button';

    private function xpath(string $file): DOMXPath
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->load($file), $file . ' is not well-formed XML');

        return new DOMXPath($dom);
    }

    public function testHyvaHandleRedeclaresTheButtonWithHyvaTemplate(): void
    {
        $xpath = $this->xpath(self::LAYOUT_DIR . '/hyva_customer_account_login.xml');

        $body = $xpath->query('/page/body')->item(0);
        self::assertInstanceOf(DOMElement::class, $body);
        $children = $xpath->query('*', $body);
        self::assertSame(1, $children->length);

        $container = $children->item(0);
        self::assertInstanceOf(DOMElement::class, $container);
        self::assertSame('referenceContainer', $container->tagName);
        self::assertSame('customer.login.container', $container->getAttribute('name'));

        $blocks = $xpath->query('*', $container);
        self::assertSame(1, $blocks->length);
        $block = $blocks->item(0);
        self::assertInstanceOf(DOMElement::class, $block);
        self::assertSame('block', $block->tagName);
        self::assertSame(self::BLOCK, $block->getAttribute('name'));
        self::assertSame('DmLab\CustomerSso\Block\Login\Sso', $block->getAttribute('class'));
        self::assertSame('customer_form_login', $block->getAttribute('before'));
        self::assertSame('DmLab_CustomerSso::login/sso-button-hyva.phtml', $block->getAttribute('template'));
        self::assertSame(0, $xpath->query('*', $block)->length);

        self::assertFileExists(self::TEMPLATE_DIR . '/login/sso-button-hyva.phtml');
    }

    public function testBaseLayoutKeepsLumaButton(): void
    {
        $xpath = $this->xpath(self::LAYOUT_DIR . '/customer_account_login.xml');

        $blocks = $xpath->query(
            '/page/body/referenceContainer[@name="customer.login.container"]/block[@name="' . self::BLOCK . '"]'
        );
        self::assertSame(1, $blocks->length);
        $block = $blocks->item(0);
        self::assertInstanceOf(DOMElement::class, $block);
        self::assertSame('DmLab\CustomerSso\Block\Login\Sso', $block->getAttribute('class'));
        self::assertSame('DmLab_CustomerSso::login/sso-button.phtml', $block->getAttribute('template'));
        self::assertSame('customer_form_login', $block->getAttribute('before'));
    }

    public function testNoCodeDependsOnHyva(): void
    {
        $offenders = [];
        foreach (['etc', 'Block', 'Controller', 'Model', 'Plugin', 'Setup', 'view'] as $dir) {
            foreach ($this->files(self::ROOT . '/' . $dir) as $file) {
                $content = (string)file_get_contents($file);
                if (str_ends_with($file, '-hyva.phtml')) {
                    $content = (string)preg_replace('/^\s*\*\s*@var\s+\\\\?Hyva\\\\\S+.*$/m', '', $content);
                }
                if (preg_match('/\\\\?\bHyva\\\\|\bHyva_[A-Z]/', $content) === 1) {
                    $offenders[] = $file;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testComposerRequiresNoHyvaPackage(): void
    {
        $json = (string)file_get_contents(self::ROOT . '/composer.json');
        $composer = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $packages = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

        self::assertSame([], array_values(array_filter(
            $packages,
            static fn (string $name): bool => str_starts_with($name, 'hyva-themes/')
        )));
    }

    public function testLoginPageIsTheOnlyHyvaHandle(): void
    {
        $files = array_map('basename', glob(self::LAYOUT_DIR . '/hyva_*.xml') ?: []);

        // Checkout and the auth popup stay Luma-only: Hyvä's popup has no jsLayout slot.
        self::assertSame(['hyva_customer_account_login.xml'], $files);
    }

    public function testNoLayoutHardcodesThemeCode(): void
    {
        foreach ($this->files(self::LAYOUT_DIR) as $file) {
            self::assertStringNotContainsString('Hyva/', (string)file_get_contents($file), $file);
        }
    }

    /**
     * @return list<string>
     */
    private function files(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
