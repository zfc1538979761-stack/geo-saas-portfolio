<?php

declare(strict_types=1);

namespace app\common\company;

final class CompanyContext
{
    private const COMPLETENESS_FIELDS = [
        'company_name',
        'brand',
        'industry',
        'products_services',
        'website',
        'introduction',
        'contacts.mobile',
        'contacts.email',
    ];

    private $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromProfile(array $profile): self
    {
        $normalized = [
            'company_id' => (int) ($profile['company_id'] ?? 0),
            'company_name' => (string) ($profile['company_name'] ?? ''),
            'brand' => self::nullableString($profile['brand'] ?? null),
            'industry' => self::nullableString($profile['industry'] ?? null),
            'products_services' => array_values((array) ($profile['products_services'] ?? [])),
            'website' => self::nullableString($profile['website'] ?? null),
            'introduction' => self::nullableString($profile['introduction'] ?? null),
        ];
        $contacts = (array) ($profile['contacts'] ?? []);
        $availability = $normalized + [
            'contacts' => [
                'mobile' => self::nullableString($contacts['mobile'] ?? null),
                'email' => self::nullableString($contacts['email'] ?? null),
            ],
        ];
        $missing = [];
        foreach (self::COMPLETENESS_FIELDS as $field) {
            if (!self::present($availability, $field)) {
                $missing[] = $field;
            }
        }
        $completed = count(self::COMPLETENESS_FIELDS) - count($missing);
        $normalized['profile_completeness'] = (int) round(
            ($completed / count(self::COMPLETENESS_FIELDS)) * 100
        );
        $normalized['missing_fields'] = $missing;
        $normalized['ai_ready'] =
            self::present($availability, 'company_name')
            && self::present($availability, 'brand')
            && self::present($availability, 'industry')
            && self::present($availability, 'products_services')
            && self::present($availability, 'introduction');

        return new self($normalized);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    private static function present(array $data, string $path): bool
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }
        return is_array($value) ? $value !== [] : ($value !== null && $value !== '');
    }

    private static function nullableString($value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
