<?php

declare(strict_types=1);

namespace App\Seo;

use Symfony\Component\Yaml\Yaml;

/**
 * Table de redirections 301 héritées de l'ancien site Drupal
 * (config/redirects.yaml, CLAUDE.md §7).
 */
final class RedirectMap
{
    /** @var array<string, string>|null */
    private ?array $exact = null;

    /** @var array<string, string>|null */
    private ?array $prefixes = null;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Renvoie le chemin de destination, ou null si aucune règle ne s'applique.
     */
    public function resolve(string $path): ?string
    {
        $this->load();

        $normalise = '/'.trim($path, '/');

        if (isset($this->exact[$normalise])) {
            return $this->exact[$normalise];
        }

        foreach ($this->prefixes ?? [] as $from => $to) {
            $from = '/'.trim($from, '/');

            if ($normalise === $from) {
                return '' === $to ? '/' : $to;
            }

            if (str_starts_with($normalise, $from.'/')) {
                $reste = substr($normalise, \strlen($from));

                return '' === $to ? $reste : rtrim($to, '/').$reste;
            }
        }

        return null;
    }

    private function load(): void
    {
        if (null !== $this->exact) {
            return;
        }

        $this->exact = [];
        $this->prefixes = [];

        if (!is_file($this->path)) {
            return;
        }

        $raw = Yaml::parseFile($this->path);

        if (!\is_array($raw)) {
            return;
        }

        $this->exact = self::stringMap($raw['exact'] ?? null);
        $this->prefixes = self::stringMap($raw['prefixes'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $from => $to) {
            if (\is_string($from) && \is_string($to)) {
                $map[$from] = $to;
            }
        }

        return $map;
    }
}
