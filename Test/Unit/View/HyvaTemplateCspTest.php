<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static Hyvä CSP rules for every `*-hyva.phtml`: no inline handlers/styles, Alpine CSP-build syntax only,
 * every inline script registered with `$hyvaCsp->registerInlineScript()`.
 */
class HyvaTemplateCspTest extends TestCase
{
    private const TEMPLATE_DIR = __DIR__ . '/../../../view/frontend/templates';
    private const ALPINE_ATTR = '/\s(x-data|x-show|x-text|x-html|x-ref|x-if|x-effect|x-model[\w.-]*'
        . '|x-(?:on|bind):[\w.:-]+|:[A-Za-z][\w.:-]*|@[A-Za-z][\w.:-]*)\s*=\s*(["\'])(.*?)\2/s';
    private const IDENTIFIER = '/^[A-Za-z_$][A-Za-z0-9_$.]*$/';

    /**
     * @return list<string>
     */
    private static function templates(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::TEMPLATE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '-hyva.phtml')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hyvaTemplates(): array
    {
        $cases = [];
        foreach (self::templates() as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    public function testHyvaTemplatesFound(): void
    {
        self::assertNotSame([], self::templates());
    }

    #[DataProvider('hyvaTemplates')]
    public function testTemplateIsCspSafe(string $file): void
    {
        self::assertSame([], self::violations((string)file_get_contents($file)), $file);
    }

    public function testRulesRejectInlineHandler(): void
    {
        self::assertContains(
            'inline event handler',
            self::violations('<a href="#" onclick="go()">x</a>')
        );
    }

    public function testRulesRejectAlpineExpression(): void
    {
        self::assertContains(
            'non-identifier Alpine value: x-data="fn(1)"',
            self::violations('<div x-data="fn(1)"></div>')
        );
    }

    public function testRulesRejectUnregisteredScript(): void
    {
        self::assertContains(
            'inline script not registered',
            self::violations("<script>window.a = 1;</script>\n<p>after</p>")
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenFixtures(): array
    {
        return [
            'inline style' => ['<p style="color:red">x</p>', 'inline style'],
            'data-mage-init' => ['<div data-mage-init=\'{}\'></div>', 'forbidden: data-mage-init'],
            'x-init' => ['<div x-init="go"></div>', 'forbidden: x-init'],
            'jQuery' => ['<p>jQuery</p>', 'forbidden: jQuery'],
            'ajax' => ['<p>$.ajax</p>', 'forbidden: $.ajax'],
            'RequireJS' => ['<p>require(["x"])</p>', 'forbidden: RequireJS'],
            'x-on expression' => [
                '<a x-on:click="go()">x</a>',
                'non-identifier Alpine value: x-on:click="go()"',
            ],
            'x-bind expression' => [
                '<a x-bind:href="url()">x</a>',
                'non-identifier Alpine value: x-bind:href="url()"',
            ],
            'unregistered x-data' => ['<div x-data="other"></div>', 'x-data not registered via Alpine.data: other'],
        ];
    }

    #[DataProvider('forbiddenFixtures')]
    public function testRulesRejectForbiddenMarkup(string $fixture, string $violation): void
    {
        self::assertContains($violation, self::violations($fixture));
    }

    public function testRulesAcceptRegisteredAlpineComponent(): void
    {
        $fixture = <<<'HTML'
<div x-data="dmlabSso"><span x-show="open" x-text="label" @click="toggle"></span></div>
<script>
    document.addEventListener('alpine:init', () => { Alpine.data('dmlabSso', () => ({})); });
</script>
<?php $hyvaCsp->registerInlineScript() ?>
HTML;

        self::assertSame([], self::violations($fixture));
    }

    /**
     * @return list<string>
     */
    private static function violations(string $content): array
    {
        $violations = [];
        if (preg_match('/\son[a-z]+\s*=\s*["\']/i', $content) === 1) {
            $violations[] = 'inline event handler';
        }
        if (preg_match('/\sstyle\s*=/i', $content) === 1) {
            $violations[] = 'inline style';
        }
        foreach (['data-mage-init', 'x-init', 'jQuery', '$.ajax'] as $needle) {
            if (str_contains($content, $needle)) {
                $violations[] = 'forbidden: ' . $needle;
            }
        }
        if (preg_match('/\b(define|require)\s*\(/', $content) === 1) {
            $violations[] = 'forbidden: RequireJS';
        }

        preg_match_all(self::ALPINE_ATTR, $content, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $attr, $quote, $value]) {
            if (preg_match(self::IDENTIFIER, $value) !== 1) {
                $violations[] = sprintf('non-identifier Alpine value: %s=%s%s%s', $attr, $quote, $value, $quote);
            } elseif ($attr === 'x-data' && !self::isRegistered($content, $value)) {
                $violations[] = 'x-data not registered via Alpine.data: ' . $value;
            }
        }

        if (preg_match('#</script>(?!\s*<\?(?:php|=)\s*\$\w+->registerInlineScript\(\))#i', $content) === 1) {
            $violations[] = 'inline script not registered';
        }

        return $violations;
    }

    private static function isRegistered(string $content, string $name): bool
    {
        $init = strpos($content, 'alpine:init');
        if ($init === false) {
            return false;
        }
        $pattern = '/Alpine\.data\(\s*([\'"])' . preg_quote($name, '/') . '\1/';

        return preg_match($pattern, substr($content, $init)) === 1;
    }
}
