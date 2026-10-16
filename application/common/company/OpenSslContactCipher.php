<?php

declare(strict_types=1);

namespace app\common\company;

use app\common\foundation\ApiErrorCode;
use app\common\foundation\RuntimeEnvironment;
use Throwable;

final class OpenSslContactCipher implements ContactCipher
{
    private const CIPHER = 'aes-256-gcm';
    private $key;

    public function __construct(string $configuredKey)
    {
        if (!RuntimeEnvironment::isValidContactEncryptionKey($configuredKey)) {
            $this->configurationError();
        }
        $decoded = base64_decode(substr($configuredKey, 7), true);
        $this->key = $decoded;
    }

    public function encrypt(array $contacts, int $companyId): string
    {
        $this->assertCompanyId($companyId);
        try {
            $plaintext = json_encode(
                [
                    'mobile' => $contacts['mobile'] ?? null,
                    'email' => $contacts['email'] ?? null,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plaintext,
                self::CIPHER,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $this->aad($companyId),
                16
            );
            if (!is_string($ciphertext) || strlen($tag) !== 16) {
                throw new \RuntimeException('Encryption failed.');
            }
            return json_encode(
                [
                    'v' => 1,
                    'alg' => 'A256GCM',
                    'iv' => base64_encode($iv),
                    'tag' => base64_encode($tag),
                    'ciphertext' => base64_encode($ciphertext),
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new CompanyException(
                ApiErrorCode::INTERNAL_ERROR,
                '联系方式安全处理失败',
                500
            );
        }
    }

    public function decrypt(string $ciphertext, int $companyId): array
    {
        $this->assertCompanyId($companyId);
        try {
            $envelope = json_decode($ciphertext, true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($envelope)
                || ($envelope['v'] ?? null) !== 1
                || ($envelope['alg'] ?? null) !== 'A256GCM'
            ) {
                throw new \RuntimeException('Invalid envelope.');
            }
            $iv = base64_decode((string) ($envelope['iv'] ?? ''), true);
            $tag = base64_decode((string) ($envelope['tag'] ?? ''), true);
            $encrypted = base64_decode((string) ($envelope['ciphertext'] ?? ''), true);
            if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16) {
                throw new \RuntimeException('Invalid envelope.');
            }
            if (!is_string($encrypted)) {
                throw new \RuntimeException('Invalid envelope.');
            }
            $plaintext = openssl_decrypt(
                $encrypted,
                self::CIPHER,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $this->aad($companyId)
            );
            if (!is_string($plaintext)) {
                throw new \RuntimeException('Authentication failed.');
            }
            $contacts = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($contacts)) {
                throw new \RuntimeException('Invalid plaintext.');
            }
            return [
                'mobile' => isset($contacts['mobile']) ? (string) $contacts['mobile'] : null,
                'email' => isset($contacts['email']) ? (string) $contacts['email'] : null,
            ];
        } catch (Throwable $exception) {
            throw new CompanyException(
                ApiErrorCode::INTERNAL_ERROR,
                '联系方式安全处理失败',
                500
            );
        }
    }

    private function aad(int $companyId): string
    {
        return 'geo-company-contact:v1:' . $companyId;
    }

    private function assertCompanyId(int $companyId): void
    {
        if ($companyId < 1) {
            throw new CompanyException(
                ApiErrorCode::INTERNAL_ERROR,
                '联系方式安全处理失败',
                500
            );
        }
    }

    private function configurationError(): void
    {
        throw new CompanyException(
            ApiErrorCode::DEPENDENCY_UNAVAILABLE,
            '联系方式加密配置不可用',
            503
        );
    }
}
