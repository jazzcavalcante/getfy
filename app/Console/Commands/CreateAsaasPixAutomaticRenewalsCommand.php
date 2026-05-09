<?php

namespace App\Console\Commands;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\AsaasPixAutomaticService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CreateAsaasPixAutomaticRenewalsCommand extends Command
{
    protected $signature = 'subscriptions:asaas-pix-auto-renewals {--limit=100}';

    protected $description = 'Cria cobrancas de renovacao Pix Automatico Asaas dentro da janela permitida.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $subscriptions = Subscription::with(['user', 'subscriptionPlan'])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('gateway_subscription_id')
            ->whereNotNull('current_period_end')
            ->limit($limit)
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->processSubscription($subscription);
        }

        return self::SUCCESS;
    }

    private function processSubscription(Subscription $subscription): void
    {
        $authorizationId = (string) $subscription->gateway_subscription_id;
        $sourceOrder = Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('gateway', 'asaas')
            ->where('metadata->asaas_pix_auto_authorization_id', $authorizationId)
            ->latest('id')
            ->first();
        if (! $sourceOrder) {
            return;
        }

        $dueDate = $subscription->current_period_end?->format('Y-m-d');
        if (! $dueDate) {
            return;
        }

        $credential = GatewayCredential::forTenant($subscription->tenant_id)
            ->where('gateway_slug', 'asaas')
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            return;
        }

        $credentials = $credential->getDecryptedCredentials();
        if (empty($credentials['api_key'])) {
            return;
        }

        $service = new AsaasPixAutomaticService($credentials);
        if (! $service->isWithinInstructionWindow($dueDate)) {
            return;
        }

        $alreadyExists = Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('gateway', 'asaas')
            ->where('is_renewal', true)
            ->whereDate('period_start', $subscription->current_period_end)
            ->exists();
        if ($alreadyExists) {
            return;
        }

        $plan = $subscription->subscriptionPlan;
        $user = $subscription->user;
        if (! $plan || ! $user) {
            return;
        }

        $periodEnd = match ($plan->interval ?? null) {
            'weekly' => $subscription->current_period_end->copy()->addWeek(),
            'quarterly' => $subscription->current_period_end->copy()->addMonths(3),
            'semi_annual' => $subscription->current_period_end->copy()->addMonths(6),
            'annual' => $subscription->current_period_end->copy()->addYear(),
            default => $subscription->current_period_end->copy()->addMonth(),
        };
        $order = Order::create([
            'tenant_id' => $subscription->tenant_id,
            'user_id' => $subscription->user_id,
            'product_id' => $subscription->product_id,
            'product_offer_id' => null,
            'subscription_plan_id' => $subscription->subscription_plan_id,
            'status' => 'pending',
            'gateway' => 'asaas',
            'gateway_id' => null,
            'is_renewal' => true,
            'amount' => (float) $plan->price,
            'email' => $user->email,
            'cpf' => $sourceOrder->cpf,
            'phone' => $sourceOrder->phone,
            'period_start' => $subscription->current_period_end,
            'period_end' => $periodEnd,
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'asaas_pix_auto_authorization_id' => $authorizationId,
            ],
        ]);

        try {
            $payment = $service->createAutomaticPayment(
                $authorizationId,
                (float) $plan->price,
                [
                    'name' => $user->name ?? $user->email,
                    'document' => $sourceOrder->cpf ?: '00000000000',
                    'email' => $user->email,
                    'phone' => $sourceOrder->phone ?? '',
                ],
                'renewal_' . $order->id,
                $dueDate,
                'Renovacao assinatura #' . $subscription->id
            );
            $order->update(['gateway_id' => $payment['payment_id']]);
        } catch (\Throwable $e) {
            $order->delete();
            Log::warning('CreateAsaasPixAutomaticRenewalsCommand: falha ao criar renovacao', [
                'subscription_id' => $subscription->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
