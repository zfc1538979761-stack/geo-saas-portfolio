<?php

declare(strict_types=1);

namespace app\common\internal;

final class InternalConfigService
{
    private const SCOPES = [
        'ai' => [
            'section' => 'ark',
            'keys' => ['api_key'],
        ],
        'supplier' => [
            'section' => 'media_market',
            'keys' => ['username', 'password', 'access_code'],
        ],
    ];

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?: $this->defaultEnvPath();
    }

    public function status(string $scope): array
    {
        $scope = $this->normalizeScope($scope);
        $definition = self::SCOPES[$scope];
        $section = $definition['section'];
        $values = $this->parse();
        $sectionValues = $values[$section] ?? [];
        $result = [];
        foreach ($definition['keys'] as $key) {
            $value = trim((string) ($sectionValues[$key] ?? ''));
            $result[$key] = [
                'configured' => $value !== '' && stripos($value, 'change-me') === false,
                'masked' => $value === '' ? '' : 'configured',
            ];
        }
        return [
            'scope' => $scope,
            'section' => $section,
            'fields' => $result,
        ];
    }

    public function replaceKey(string $scope, string $key, string $value): void
    {
        $scope = $this->normalizeScope($scope);
        $definition = self::SCOPES[$scope];
        if (!in_array($key, $definition['keys'], true)) {
            throw new \InvalidArgumentException('The requested credential is not supported for replacement.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 4096 || preg_match('/[\r\n]/', $value) === 1) {
            throw new \InvalidArgumentException('Credential replacement value is invalid.');
        }
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \RuntimeException('Environment configuration file is not readable.');
        }
        $content = file_get_contents($this->path);
        if ($content === false) {
            throw new \RuntimeException('Environment configuration file could not be read.');
        }
        $eol = strpos($content, "\r\n") !== false ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if ($lines === false) {
            throw new \RuntimeException('Environment configuration file could not be parsed.');
        }
        $targetSection = $definition['section'];
        $currentSection = null;
        $found = false;
        $output = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^\[([A-Za-z0-9_.]+)\]\s*$/D', $trimmed, $m) === 1) {
                $currentSection = $m[1];
                $output[] = $line;
                continue;
            }
            if ($currentSection === $targetSection && preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $m) === 1 && $m[1] === $key) {
                $leading = strpos($line, $m[1]);
                $indent = $leading === false ? '' : substr($line, 0, $leading);
                $output[] = $indent . $key . ' = ' . $value;
                $found = true;
                continue;
            }
            $output[] = $line;
        }
        if (!$found) {
            $output[] = '';
            if ($currentSection !== $targetSection) {
                $output[] = '[' . $targetSection . ']';
            }
            $output[] = $key . ' = ' . $value;
        }
        $newContent = implode($eol, $output) . $eol;
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $newContent, LOCK_EX) === false) {
            throw new \RuntimeException('Environment configuration file could not be written.');
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Environment configuration file could not be replaced.');
        }
    }

    private function parse(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $content = file_get_contents($this->path);
        if ($content === false) {
            return [];
        }
        $result = [];
        $section = null;
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || isset($trimmed[0]) && $trimmed[0] === '#') {
                continue;
            }
            if (preg_match('/^\[([A-Za-z0-9_.]+)\]\s*$/D', $trimmed, $m) === 1) {
                $section = $m[1];
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/D', $trimmed, $m) === 1) {
                $result[$section ?? ''][$m[1]] = trim($m[2]);
            }
        }
        return $result;
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (!isset(self::SCOPES[$scope])) {
            throw new \InvalidArgumentException('Internal config scope is invalid.');
        }
        return $scope;
    }

    private function defaultEnvPath(): string
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
    }
}
