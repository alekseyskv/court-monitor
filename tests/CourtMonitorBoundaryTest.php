<?php

declare(strict_types=1);

namespace Lawmatic\CourtMonitor\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Граница пакета: код в src/ опирается только на себя и на зависимости из
 * composer.json. Composer этого не гарантирует — транзитивные пакеты приложения
 * тоже доступны автозагрузчику, и лишний `use` заметили бы только у потребителя.
 *
 * Добавляете зависимость осознанно — впишите её в ALLOWED и в require composer.json.
 */
final class CourtMonitorBoundaryTest extends TestCase
{
    private const DIR = __DIR__ . '/../src';

    private const OWN_NAMESPACE = 'Lawmatic\\CourtMonitor\\';

    /** Пространства имён зависимостей будущего пакета. */
    private const ALLOWED = [
        self::OWN_NAMESPACE,
        'Psr\\Log\\',
        'Symfony\\Contracts\\HttpClient\\',
    ];

    public function testImportsOnlyOwnNamespaceAndPackageDependencies(): void
    {
        $violations = [];
        foreach ($this->files() as $relative => $code) {
            preg_match_all('/^use\s+(?:function\s+|const\s+)?([^;\s]+)/m', $code, $matches);
            foreach ($matches[1] as $import) {
                if (!$this->isAllowed($import)) {
                    $violations[] = $relative . ': use ' . $import;
                }
            }
        }

        self::assertSame([], $violations, 'Пакет импортирует то, чего нет в его зависимостях');
    }

    public function testHasNoPhpAttributes(): void
    {
        $violations = [];
        foreach ($this->files() as $relative => $code) {
            if (preg_match('/^\s*#\[/m', $code) === 1) {
                $violations[] = $relative;
            }
        }

        self::assertSame([], $violations, 'Атрибуты (#[Autowire] и т.п.) привязывают код к фреймворку — настройки передавайте через конструктор');
    }

    private function isAllowed(string $import): bool
    {
        if (!str_contains($import, '\\')) {
            return true; // встроенные классы PHP: use RuntimeException;
        }

        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($import, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> относительный путь => код
     */
    private function files(): array
    {
        $dir = realpath(self::DIR);
        self::assertNotFalse($dir, 'Нет папки ' . self::DIR);

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        self::assertNotEmpty($files, 'В src/ не найдено PHP-файлов');

        return $files;
    }
}
