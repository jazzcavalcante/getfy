<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AsaasWebhookController extends Controller
{
    /**
     * Handle Asaas webhook (POST /webhooks/gateways/asaas).
     * Payload: event (PAYMENT_RECEIVED, PAYMENT_CONFIRMED, PAYMENT_OVERDUE, etc.), payment (object with id).
     * Always respond 200 when order not found to avoid retries.
     */
    public function handle(Request $request): JsonResponse
    {
        $eventType = strtoupper((string) $request->input('event', ''));
        if (str_starts_with($eventType, 'PIX_AUTOMATIC_RECURRING_')) {
            return $this->handlePixAutomatic($request, $eventType);
        }

        $payment = $request->input('payment');
        if (! is_array($payment)) {
            return response()->json(['received' => true]);
        }
        $transactionId = $payment['id'] ?? null;
        if ($transactionId === null || $transactionId === '') {
            return response()->json(['received' => true]);
        }
        $transactionId = (string) $transactionId;

        $order = $this->findOrderForPayment($payment, $transactionId);
        if (! $order) {
            Log::debug('AsaasWebhook: order not found', ['gateway_id' => $transactionId]);
            return response()->json(['received' => true]);
        }

        if (! $this->verifyWebhookSignature('asaas', $order->tenant_id, $request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event = 'order.pending';
        $mappedStatus = 'pending';

        if (in_array($eventType, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true)) {
            $event = 'order.paid';
            $mappedStatus = 'paid';
        } elseif (in_array($eventType, ['PAYMENT_CANCELLED', 'PAYMENT_REFUNDED'], true)) {
            $event = 'order.cancelled';
            $mappedStatus = 'cancelled';
        } elseif (in_array($eventType, ['PAYMENT_OVERDUE'], true)) {
            $event = 'order.pending';
            $mappedStatus = 'pending';
        }

        ProcessPaymentWebhook::dispatchSync('asaas', $transactionId, $event, $mappedStatus, $request->all());

        return response()->json(['received' => true]);
    }

    private function handlePixAutomatic(Request $request, string $eventType): JsonResponse
    {
        $authorization = $request->input('authorization');
        $instruction = $request->input('paymentInstruction');
        $paymentId = is_array($instruction) && isset($instruction['payment']) ? (string) $instruction['payment'] : null;

        if ($paymentId !== null && $paymentId !== '') {
            $order = Order::where('gateway', 'asaas')->where('gateway_id', $paymentId)->first();
            if ($order && ! $this->verifyWebhookSignature('asaas', $order->tenant_id, $request)) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
            return response()->json(['received' => true]);
        }

        $authorizationId = null;
        if (is_array($authorization) && isset($authorization['id'])) {
            $authorizationId = (string) $authorization['id'];
        } elseif (is_array($instruction) && isset($instruction['authorization']['id'])) {
            $authorizationId = (string) $instruction['authorization']['id'];
        }

        if ($authorizationId === null || $authorizationId === '') {
            return response()->json(['received' => true]);
        }

        $order = Order::where('gateway', 'asaas')
            ->where(function ($query) use ($authorizationId) {
                $query->where('gateway_id', $authorizationId)
                    ->orWhere('metadata->asaas_pix_auto_authorization_id', $authorizationId);
            })
            ->latest('id')
            ->first();
        if (! $order) {
            Log::debug('AsaasWebhook Pix Automatico: order not found', ['authorization_id' => $authorizationId, 'event' => $eventType]);
            return response()->json(['received' => true]);
        }

        if (! $this->verifyWebhookSignature('asaas', $order->tenant_id, $request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (in_array($eventType, [
            'PIX_AUTOMATIC_RECURRING_AUTHORIZATION_CANCELLED',
            'PIX_AUTOMATIC_RECURRING_AUTHORIZATION_EXPIRED',
            'PIX_AUTOMATIC_RECURRING_AUTHORIZATION_REFUSED',
        ], true)) {
            Log::info('AsaasWebhook Pix Automatico authorization inactive', [
                'order_id' => $order->id,
                'authorization_id' => $authorizationId,
                'event' => $eventType,
            ]);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function findOrderForPayment(array $payment, string $transactionId): ?Order
    {
        $order = Order::where('gateway', 'asaas')->where('gateway_id', $transactionId)->first();
        if ($order) {
            return $order;
        }

        $externalReference = $payment['externalReference'] ?? null;
        if (is_string($externalReference) && preg_match('/^(?:order|renewal)_(\d+)$/', $externalReference, $matches)) {
            $order = Order::where('gateway', 'asaas')->whereKey((int) $matches[1])->first();
            if ($order) {
                $order->update(['gateway_id' => $transactionId]);
                return $order;
            }
        }

        $conciliationIdentifier = $payment['conciliationIdentifier'] ?? $payment['pixTransaction']['conciliationIdentifier'] ?? null;
        if (is_string($conciliationIdentifier) && $conciliationIdentifier !== '') {
            $order = Order::where('gateway', 'asaas')
                ->where('metadata->asaas_pix_auto_conciliation_identifier', $conciliationIdentifier)
                ->latest('id')
                ->first();
            if ($order) {
                $order->update(['gateway_id' => $transactionId]);
                return $order;
            }
        }

        return null;
    }

    /**
     * Verifica assinatura do webhook quando webhook_secret estiver configurado.
     */
    private function verifyWebhookSignature(string $gatewaySlug, ?int $tenantId, Request $request): bool
    {
        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', $gatewaySlug)
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            return true;
        }
        $credentials = $credential->getDecryptedCredentials();
        $secret = $credentials['webhook_secret'] ?? null;
        if ($secret === null || $secret === '') {
            return true;
        }
        $signature = $request->header('X-Webhook-Signature') ?? $request->header('X-Signature');
        if (! is_string($signature) || $signature === '') {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $signature);
    }
}
