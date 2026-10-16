<?php

declare(strict_types=1);

namespace app\common\company;

use app\common\foundation\ApiErrorCode;

final class CompanyProfileValidator
{
    public const COMPANY_NAME_MAX = 191;
    public const BRAND_MAX = 100;
    public const INDUSTRY_MAX = 100;
    public const WEBSITE_MAX = 2048;
    public const INTRODUCTION_MAX = 5000;
    public const PRODUCT_MAX_COUNT = 50;
    public const PRODUCT_MAX_LENGTH = 100;
    public const EMAIL_MAX = 254;

    public function validate(array $input): array
    {
        if (array_key_exists('company_id', $input)) {
            $this->invalid('company_id', '请求中不得指定企业ID');
        }

        $companyName = $this->requiredText(
            $input['company_name'] ?? '',
            'company_name',
            self::COMPANY_NAME_MAX
        );
        $brand = $this->optionalText($input['brand'] ?? null, 'brand', self::BRAND_MAX);
        $industry = $this->optionalText($input['industry'] ?? null, 'industry', self::INDUSTRY_MAX);
        $website = $this->website($input['website'] ?? null);
        $introduction = $this->optionalText(
            $input['introduction'] ?? null,
            'introduction',
            self::INTRODUCTION_MAX
        );
        $products = $this->products($input['products_services'] ?? []);
        $mobile = $this->mobile($input['mobile'] ?? null);
        $email = $this->email($input['email'] ?? null);

        return [
            'company_name' => $companyName,
            'brand' => $brand,
            'industry' => $industry,
            'products_services' => $products,
            'website' => $website,
            'introduction' => $introduction,
            'contacts' => [
                'mobile' => $mobile,
                'email' => $email,
            ],
        ];
    }

    private function requiredText($value, string $field, int $maximum): string
    {
        $normalized = $this->normalizeText($value, $field);
        if ($normalized === '') {
            $this->invalid($field, '企业名称不能为空');
        }
        if ($this->length($normalized) > $maximum) {
            $this->invalid($field, '字段长度超过限制');
        }
        return $normalized;
    }

    private function optionalText($value, string $field, int $maximum): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = $this->normalizeText($value, $field);
        if ($normalized === '') {
            return null;
        }
        if ($this->length($normalized) > $maximum) {
            $this->invalid($field, '字段长度超过限制');
        }
        return $normalized;
    }

    private function normalizeText($value, string $field): string
    {
        if (!is_string($value)) {
            $this->invalid($field, '字段类型不正确');
        }
        if (preg_match('//u', $value) !== 1) {
            $this->invalid($field, '字段编码不正确');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $this->invalid($field, '字段包含不允许的控制字符');
        }
        return trim($value);
    }

    private function products($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                $this->invalid('products_services', '产品/服务必须是数组');
            }
            $value = $decoded;
        }
        if (!is_array($value) || array_values($value) !== $value) {
            $this->invalid('products_services', '产品/服务必须是顺序数组');
        }
        if (count($value) > self::PRODUCT_MAX_COUNT) {
            $this->invalid('products_services', '产品/服务数量超过限制');
        }

        $normalized = [];
        $seen = [];
        foreach ($value as $item) {
            $item = $this->normalizeText($item, 'products_services');
            if ($item === '' || $this->length($item) > self::PRODUCT_MAX_LENGTH) {
                $this->invalid('products_services', '产品/服务单项无效或长度超过限制');
            }
            $comparison = function_exists('mb_strtolower')
                ? mb_strtolower($item, 'UTF-8')
                : strtolower($item);
            if (isset($seen[$comparison])) {
                $this->invalid('products_services', '产品/服务不能重复');
            }
            $seen[$comparison] = true;
            $normalized[] = $item;
        }
        return $normalized;
    }

    private function website($value): ?string
    {
        $website = $this->optionalText($value, 'website', self::WEBSITE_MAX);
        if ($website === null) {
            return null;
        }
        if (filter_var($website, FILTER_VALIDATE_URL) === false) {
            $this->invalid('website', '官网地址格式不正确');
        }
        $scheme = strtolower((string) parse_url($website, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $this->invalid('website', '官网只允许http或https协议');
        }
        return $website;
    }

    private function mobile($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 32) {
            $this->invalid('mobile', '手机号格式不正确');
        }
        $normalized = preg_replace('/[\s()-]+/', '', trim($value));
        if (
            !is_string($normalized)
            || preg_match('/^\+?[1-9][0-9]{6,14}$/', $normalized) !== 1
        ) {
            $this->invalid('mobile', '手机号格式不正确');
        }
        return $normalized;
    }

    private function email($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $this->invalid('email', '邮箱格式不正确');
        }
        $normalized = strtolower(trim($value));
        if (
            strlen($normalized) > self::EMAIL_MAX
            || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false
        ) {
            $this->invalid('email', '邮箱格式不正确');
        }
        return $normalized;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function invalid(string $field, string $message): void
    {
        throw new CompanyException(
            ApiErrorCode::INVALID_REQUEST,
            $message,
            422,
            ['field' => $field]
        );
    }
}
