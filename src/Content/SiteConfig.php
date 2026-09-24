<?php

declare(strict_types=1);

namespace App\Content;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration globale du site, lue depuis content/site.yaml.
 *
 * Exposée à Twig sous la variable `site` (config/packages/twig.yaml).
 */
final readonly class SiteConfig
{
    /**
     * @param array{email: string, telephone: string, adresse: string} $contact
     * @param list<array{label: string, href: string, icone: string}>  $reseaux
     * @param list<array{label: string, href: string}>                 $liensPied
     */
    public function __construct(
        public string $name,
        public string $baseline,
        public array $contact,
        public array $reseaux,
        public array $liensPied,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(\sprintf('Fichier de configuration du site introuvable : %s.', $path));
        }

        $raw = Yaml::parseFile($path);

        if (!\is_array($raw)) {
            throw new RuntimeException(\sprintf('%s doit contenir un mapping YAML.', $path));
        }

        /** @var array<string, mixed> $data */
        $data = $raw;
        $contact = self::section($data, 'contact');
        $pied = self::section($data, 'pied');

        return new self(
            name: self::str($data, 'name'),
            baseline: self::str($data, 'baseline'),
            contact: [
                'email' => self::str($contact, 'email'),
                'telephone' => self::str($contact, 'telephone'),
                'adresse' => self::str($contact, 'adresse'),
            ],
            reseaux: self::reseaux($data),
            liensPied: self::liens($pied),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function section(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $k => $v) {
            if (\is_string($k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{label: string, href: string, icone: string}>
     */
    private static function reseaux(array $data): array
    {
        $value = $data['reseaux'] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            /* @var array<string, mixed> $entry */
            $out[] = [
                'label' => self::str($entry, 'label'),
                'href' => self::str($entry, 'href'),
                'icone' => self::str($entry, 'icone'),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $section
     *
     * @return list<array{label: string, href: string}>
     */
    private static function liens(array $section): array
    {
        $value = $section['liens'] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            /* @var array<string, mixed> $entry */
            $out[] = [
                'label' => self::str($entry, 'label'),
                'href' => self::str($entry, 'href'),
            ];
        }

        return $out;
    }
}
