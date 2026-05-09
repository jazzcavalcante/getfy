<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsaasPixAutomaticService
{
    private const BASE_URL_PRODUCTION = 'https://api.asaas.com/v3';

    private const BASE_URL_SANDBOX = 'https://api-sandbox.asaas.com/v3';

    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private array $credentials) {}

    private function baseUrl(): string
    {
        $sandbox = isset($this->credentials['sandbox']) && filter_var($this->credentials['sandbox'], FILTER_VALIDATE_BOOLEAN);

        return $sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    private function apiKey(): string
    {
        $key = trim((string) ($this->credentials['api_key'] ?? ''));
        if ($key === '') {
            throw new \RuntimeException('Asaas: API Key nao configurada.');
        }

        return $key;
    }

    private function http(int $timeout = 20): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'access_token' => $this->apiKey(),
            'Content-Type' => 'application/json',
            'User-Agent' => config('app.name', 'Checkout'),
        ])->acceptJson()->timeout($timeout)->withOptions(['connect_timeout' => min(60, max(2, (int) ceil($timeout / 4)))]);
    }

    /**
     * @param  array{name: string, document: string, email: string, phone?: string, address?: array}  $consumer
     */
    public function ensureCustomer(array $consumer, string $externalId): string
    {
        $document = preg_replace('/\D/', '', $consumer['document'] ?? '');
        if (strlen($document) < 11) {
            $document = '00000000000';
        }

        $body = [
            'name' => trim($consumer['name'] ?? '') ?: 'Cliente',
            'cpfCnpj' => $document,
            'email' => $consumer['email'] ?? '',
            'externalReference' => 'pix_auto_' . $externalId,
        ];

        $phone = $this->normalizePhone($consumer['phone'] ?? '');
        if ($phone !== '') {
            $body['mobilePhone'] = $phone;
        }

        $response = $this->http()->post($this->baseUrl() . '/customers', $body);
        if (! $response->successful()) {
            $msg = $this->errorMessage($response, 'Nao foi possivel criar o cliente.');
            Log::warning('AsaasPixAutomaticService ensureCustomer failed', [
                'status' => $response->status(),
                'external_id' => $externalId,
                'message' => $msg,
                'response' => $this->safeResponseBody($response),
            ]);
            throw new \RuntimeException('Asaas: ' . $msg);
        }

        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('Asaas: resposta sem ID do cliente.');
        }

        return $id;
    }

    /**
     * @param  array{name: string, document: string, email: string, phone?: string}  $consumer
     * @return array{authorization_id: string, copy_paste: string, conciliation_identifier: string|null, raw: array<string, mixed>}
     */
    public function createAuthorization(
        float $amount,
        array $consumer,
        string $externalId,
        string $frequency,
        string $startDate,
        ?string $finishDate,
        string $description
    ): array {
        $customerId = $this->ensureCustomer($consumer, $externalId);
        $body = [
            'frequency' => $frequency,
            'contractId' => mb_substr($externalId, 0, 35),
            'startDate' => $startDate,
            'value' => round($amount, 2),
            'description' => mb_substr($description, 0, 35),
            'customerId' => $customerId,
            'immediateQrCode' => [
                'originalValue' => round($amount, 2),
                'expirationSeconds' => 3600,
            ],
        ];
        if ($finishDate !== null && $finishDate !== '') {
            $body['finishDate'] = $finishDate;
        }

        $response = $this->http(30)->post($this->baseUrl() . '/pix/automatic/authorizations', $body);
        if (! $response->successful()) {
            $msg = $this->errorMessage($response, 'Nao foi possivel criar a autorizacao Pix Automatico.');
            Log::warning('AsaasPixAutomaticService createAuthorization failed', [
                'status' => $response->status(),
                'external_id' => $externalId,
                'message' => $msg,
                'response' => $this->safeResponseBody($response),
            ]);
            throw new \RuntimeException('Asaas: ' . $msg);
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('Asaas: resposta invalida da autorizacao Pix Automatico.');
        }

        $authorizationId = $data['id'] ?? $data['authorizationId'] ?? null;
        if (! is_string($authorizationId) || $authorizationId === '') {
            throw new \RuntimeException('Asaas: resposta sem ID da autorizacao Pix Automatico.');
        }

        $immediateQrCode = $data['immediateQrCode'] ?? [];
        $copyPaste = is_array($immediateQrCode)
            ? (string) ($immediateQrCode['payload'] ?? $immediateQrCode['copyPaste'] ?? $immediateQrCode['qrCode'] ?? '')
            : '';
        if ($copyPaste === '') {
            Log::warning('AsaasPixAutomaticService authorization without QR payload', ['authorization_id' => $authorizationId]);
        }

        $conciliationIdentifier = is_array($immediateQrCode)
            ? ($immediateQrCode['conciliationIdentifier'] ?? null)
            : null;

        return [
            'authorization_id' => $authorizationId,
            'copy_paste' => $copyPaste,
            'conciliation_identifier' => is_string($conciliationIdentifier) ? $conciliationIdentifier : null,
            'raw' => $data,
        ];
    }

    /**
     * @param  array{name?: string, document?: string, email?: string, phone?: string}  $consumer
     * @return array{payment_id: string, raw: array<string, mixed>}
     */
    public function createAutomaticPayment(
        string $authorizationId,
        float $amount,
        array $consumer,
        string $externalReference,
        string $dueDate,
        string $description
    ): array {
        if (! $this->isWithinInstructionWindow($dueDate)) {
            throw new \RuntimeException('Asaas: a cobranca Pix Automatico deve ser criada entre 2 e 10 dias uteis antes do vencimento.');
        }

        $customerId = $this->ensureCustomer($consumer, $externalReference);
        $body = [
            'customer' => $customerId,
            'billingType' => 'PIX',
            'value' => round($amount, 2),
            'dueDate' => $dueDate,
            'description' => mb_substr($description, 0, 500),
            'externalReference' => $externalReference,
            'pixAutomaticAuthorizationId' => $authorizationId,
        ];

        $response = $this->http(30)->post($this->baseUrl() . '/payments', $body);
        if (! $response->successful()) {
            $msg = $this->errorMessage($response, 'Nao foi possivel criar a cobranca Pix Automatico.');
            Log::warning('AsaasPixAutomaticService createAutomaticPayment failed', [
                'status' => $response->status(),
                'authorization_id' => $authorizationId,
                'message' => $msg,
                'response' => $this->safeResponseBody($response),
            ]);
            throw new \RuntimeException('Asaas: ' . $msg);
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['id'])) {
            throw new \RuntimeException('Asaas: resposta sem ID da cobranca Pix Automatico.');
        }

        return [
            'payment_id' => (string) $data['id'],
            'raw' => $data,
        ];
    }

    public function isWithinInstructionWindow(string $dueDate): bool
    {
        try {
            $target = Carbon::parse($dueDate)->startOfDay();
        } catch (\Throwable) {
            return false;
        }

        $today = Carbon::today();
        if ($target->lessThanOrEqualTo($today)) {
            return false;
        }

        $businessDays = 0;
        $cursor = $today->copy();
        while ($cursor->lt($target)) {
            $cursor->addDay();
            if (! $cursor->isWeekend()) {
                $businessDays++;
            }
        }

        return $businessDays >= 2 && $businessDays <= 10;
    }

    public static function intervalToFrequency(?string $interval): string
    {
        return match ($interval) {
            'weekly' => 'WEEKLY',
            'quarterly' => 'QUARTERLY',
            'semi_annual', 'semiannually' => 'SEMIANNUALLY',
            'annual', 'annually', 'yearly' => 'ANNUALLY',
            default => 'MONTHLY',
        };
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) > 11 && substr($digits, 0, 2) === '55') {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) === 10) {
            $digits = substr($digits, 0, 2) . '9' . substr($digits, 2);
        }

        return strlen($digits) === 11 ? $digits : '';
    }

    private function errorMessage(\Illuminate\Http\Client\Response $response, string $fallback): string
    {
        $errors = $response->json('errors', []);
        if (is_array($errors) && isset($errors[0]['description']) && is_string($errors[0]['description'])) {
            if (($errors[0]['code'] ?? null) === 'invalid_action') {
                return 'Acao recusada pelo Asaas. Verifique se a conta possui chave Pix cadastrada, permissao PIX_AUTOMATIC:WRITE e Pix Automatico habilitado no ambiente usado.';
            }
            return $errors[0]['description'];
        }
        $error = $response->json('error');
        if (is_string($error) && $error !== '') {
            return $error;
        }
        $message = $response->json('message');
        if (is_string($message) && $message !== '') {
            return $message;
        }
        $body = $this->safeResponseBody($response);
        if ($body !== '') {
            return mb_substr($body, 0, 240);
        }

        return $fallback;
    }

    private function safeResponseBody(\Illuminate\Http\Client\Response $response): string
    {
        $body = trim($response->body());
        if ($body === '') {
            return '';
        }

        return mb_substr($body, 0, 1000);
    }
}
