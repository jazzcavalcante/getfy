<?php

namespace App\Http\Controllers;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Events\SubscriptionRenewed;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\AsaasPixAutomaticService;
use App\Services\EfiPixRecorrenteService;
use App\Services\PaymentService;
use App\Support\FakeConsumerData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class RenewalController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $subscription = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->where('renewal_token', $token)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        if (! $subscription || $subscription->subscriptionPlan->isLifetime()) {
            return redirect()->route('login')->with('error', 'Link de renovação inválido ou expirado.');
        }

        $product = $subscription->product;
        $plan = $subscription->subscriptionPlan;
        $amount = (float) $plan->price;
        $currency = $plan->getCurrencyOrDefault();
        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        if ($currency !== 'BRL') {
            $amount = $currency === 'EUR' ? $amount / ($rates['brl_eur'] ?? 0.16) : $amount / ($rates['brl_usd'] ?? 0.18);
        }

        $tenantId = $subscription->tenant_id;
        $availablePaymentMethods = $this->buildAvailablePaymentMethods($product, $subscription);
        $savedPaymentMethods = $subscription->user->savedPaymentMethods()->forTenant($tenantId)->get()->map(fn ($m) => [
            'id' => $m->id,
            'type' => $m->type,
            'last_four' => $m->last_four,
            'brand' => $m->brand,
        ])->all();

        $productArray = [
            'id' => $product->id,
            'name' => $product->name,
            'checkout_slug' => $plan->checkout_slug,
        ];
        $planArray = [
            'id' => $plan->id,
            'name' => $plan->name,
            'price' => (float) $plan->price,
            'currency' => $currency,
            'interval' => $plan->interval,
        ];

        return Inertia::render('Renewal/Show', [
            'token' => $token,
            'subscription' => [
                'id' => $subscription->id,
                'current_period_end' => $subscription->current_period_end?->toDateString(),
            ],
            'product' => $productArray,
            'plan' => $planArray,
            'amount' => round($amount, 2),
            'amount_brl' => round($amount, 2),
            'available_payment_methods' => $availablePaymentMethods,
            'saved_payment_methods' => $savedPaymentMethods,
        ]);
    }

    public function process(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'payment_method' => ['nullable', 'string', 'in:pix,card,boleto,pix_auto,manual'],
        ]);

        $subscription = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->where('renewal_token', $request->input('token'))
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        if (! $subscription || $subscription->subscriptionPlan->isLifetime()) {
            return redirect()->route('login')->with('error', 'Link de renovação inválido ou expirado.');
        }

        $product = $subscription->product;
        $plan = $subscription->subscriptionPlan;
        $user = $subscription->user;
        $tenantId = $subscription->tenant_id;
        $amount = (float) $plan->price;
        $currency = $plan->getCurrencyOrDefault();
        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        if ($currency !== 'BRL') {
            $amount = $currency === 'EUR' ? $amount / ($rates['brl_eur'] ?? 0.16) : $amount / ($rates['brl_usd'] ?? 0.18);
        }

        [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        $paymentMethod = $request->input('payment_method', 'manual');

        $orderPayload = [
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_offer_id' => null,
            'subscription_plan_id' => $plan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => true,
            'amount' => $amount,
            'email' => $user->email,
            'cpf' => null,
            'phone' => null,
            'customer_ip' => $request->ip(),
            'coupon_code' => null,
        ];

        if ($paymentMethod === 'pix_auto' && $subscription->gateway_subscription_id) {
            $asaasSourceOrder = Order::where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->where('subscription_plan_id', $plan->id)
                ->where('gateway', 'asaas')
                ->where('metadata->asaas_pix_auto_authorization_id', $subscription->gateway_subscription_id)
                ->latest('id')
                ->first();
            if ($asaasSourceOrder) {
                $credential = GatewayCredential::forTenant($tenantId)
                    ->where('gateway_slug', 'asaas')
                    ->where('is_connected', true)
                    ->first();
                if ($credential) {
                    $credentials = $credential->getDecryptedCredentials();
                    if (! empty($credentials['api_key'])) {
                        $dataDeVencimento = $periodEnd ? $periodEnd->format('Y-m-d') : now()->addMonth()->format('Y-m-d');
                        $service = new AsaasPixAutomaticService($credentials);
                        if (! $service->isWithinInstructionWindow($dataDeVencimento)) {
                            return redirect()->route('renewal.show', $request->input('token'))
                                ->with('info', 'A cobrança PIX automático será agendada automaticamente dentro da janela aceita pelo Asaas.');
                        }

                        $order = Order::create(array_merge($orderPayload, [
                            'status' => 'pending',
                            'gateway' => 'asaas',
                            'gateway_id' => null,
                            'cpf' => $asaasSourceOrder->cpf,
                            'phone' => $asaasSourceOrder->phone,
                            'metadata' => ['checkout_payment_method' => 'pix_auto'],
                        ]));
                        event(new OrderPending($order));
                        try {
                            $consumer = [
                                'name' => $user->name ?? $user->email,
                                'document' => $asaasSourceOrder->cpf ?: '00000000000',
                                'email' => $user->email,
                                'phone' => $asaasSourceOrder->phone ?? '',
                            ];
                            $payment = $service->createAutomaticPayment(
                                $subscription->gateway_subscription_id,
                                $amount,
                                $consumer,
                                'renewal_' . $order->id,
                                $dataDeVencimento,
                                'Renovacao assinatura #' . $subscription->id
                            );
                            $order->update(['gateway_id' => $payment['payment_id']]);

                            return redirect()->route('renewal.show', $request->input('token'))
                                ->with('info', 'O débito PIX automático foi agendado. Você receberá a confirmação quando o pagamento for processado.');
                        } catch (\Throwable $e) {
                            $order->delete();
                            return back()->with('error', $e->getMessage() ?: 'Não foi possível agendar o PIX automático. Tente outro método.');
                        }
                    }
                }
            }

            $credential = GatewayCredential::forTenant($tenantId)
                ->where('gateway_slug', 'efi')
                ->where('is_connected', true)
                ->first();
            if ($credential) {
                $credentials = $credential->getDecryptedCredentials();
                if (! empty($credentials['certificate_path'])) {
                    $order = Order::create(array_merge($orderPayload, [
                        'status' => 'pending',
                        'gateway' => 'efi',
                        'gateway_id' => null,
                        'metadata' => ['checkout_payment_method' => 'pix_auto'],
                    ]));
                    event(new OrderPending($order));
                    try {
                        $idRec = $subscription->gateway_subscription_id;
                        $dataDeVencimento = $periodEnd ? $periodEnd->format('Y-m-d') : now()->addMonth()->format('Y-m-d');
                        $base = 'pixautorenov' . $order->id;
                        $txid = $base . \Illuminate\Support\Str::random(max(26 - strlen($base), 10));
                        $txid = substr($txid, 0, 35);
                        $devedor = [
                            'name' => $user->name ?? $user->email,
                            'email' => $user->email,
                        ];
                        $service = new EfiPixRecorrenteService($credentials);
                        $service->createCobrancaRecorrente(
                            $idRec,
                            $amount,
                            $dataDeVencimento,
                            $txid,
                            $devedor,
                            'Renovação assinatura #' . $subscription->id
                        );
                        $order->update(['gateway_id' => $txid]);
                        return redirect()->route('renewal.show', $request->input('token'))
                            ->with('info', 'O débito PIX automático foi agendado. Você receberá a confirmação quando o pagamento for processado.');
                    } catch (\Throwable $e) {
                        $order->delete();
                        return back()->with('error', $e->getMessage() ?: 'Não foi possível agendar o PIX automático. Tente outro método.');
                    }
                }
            }
        }

        if ($paymentMethod === 'pix') {
            $order = Order::create(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'metadata' => ['checkout_payment_method' => 'pix'],
            ]));
            event(new OrderPending($order));
            try {
                $paymentService = app(PaymentService::class);
                $fake = FakeConsumerData::getForGateway($order->id);
                $consumer = [
                    'name' => $user->name ?? $user->email,
                    'document' => $fake['document'],
                    'email' => $user->email,
                ];
                $pixResult = $paymentService->createPixPayment($order, $product, $consumer);
                event(new PixGenerated($order, [
                    'qrcode' => $pixResult['qrcode'] ?? null,
                    'copy_paste' => $pixResult['copy_paste'] ?? null,
                    'transaction_id' => $pixResult['transaction_id'] ?? null,
                ]));
                $pixToken = \Illuminate\Support\Str::random(32);
                session()->put('pix_display.' . $pixToken, [
                    'order_id' => $order->id,
                    'qrcode' => $pixResult['qrcode'] ?? null,
                    'copy_paste' => $pixResult['copy_paste'] ?? null,
                    'amount' => $amount,
                    'product_name' => $product->name,
                    'checkout_slug' => $plan->checkout_slug,
                    'redirect_after_purchase' => null,
                    'customer_name' => $user->name,
                    'customer_email' => $user->email,
                    'customer_phone' => null,
                    'created_at' => time(),
                ]);
                return redirect()->route('checkout.pix', ['token' => $pixToken]);
            } catch (\Throwable $e) {
                $order->delete();
                return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX. Tente novamente.');
            }
        }

        if ($paymentMethod === 'card' || $paymentMethod === 'boleto') {
            $order = Order::create(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => $paymentMethod,
                'gateway_id' => null,
            ]));
            event(new OrderPending($order));
            if ($paymentMethod === 'boleto') {
                event(new BoletoGenerated($order));
            }
            $methodLabel = $paymentMethod === 'card' ? 'Cartão' : 'Boleto';
            return redirect()->route('renewal.show', $request->input('token'))
                ->with('info', "Pagamento por {$methodLabel} em breve. Você receberá as instruções por e-mail.");
        }

        $order = Order::create(array_merge($orderPayload, [
            'status' => 'completed',
            'gateway' => 'manual',
        ]));
        $subscription->update([
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ]);
        event(new SubscriptionRenewed($subscription->fresh()));
        event(new OrderCompleted($order));

        return redirect()->route('renewal.show', $request->input('token'))
            ->with('success', 'Renovação concluída. Seu acesso foi estendido.');
    }

    /**
     * @param  Subscription|null  $subscription  Quando presente e com gateway_subscription_id (idRec), inclui pix_auto se Efí estiver conectada.
     */
    private function buildAvailablePaymentMethods($product, ?Subscription $subscription = null): array
    {
        $tenantId = $product->tenant_id;
        $config = $product->checkout_config ?? [];
        $pg = $config['payment_gateways'] ?? [];
        $orderRaw = Setting::get('gateway_order', null, $tenantId);
        if (is_string($orderRaw)) {
            $orderRaw = json_decode($orderRaw, true);
        }
        $defaultOrder = config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]);
        $order = is_array($orderRaw) ? $orderRaw : $defaultOrder;
        $order = [
            'pix' => $order['pix'] ?? $defaultOrder['pix'] ?? [],
            'card' => $order['card'] ?? $defaultOrder['card'] ?? [],
            'boleto' => $order['boleto'] ?? $defaultOrder['boleto'] ?? [],
            'pix_auto' => $order['pix_auto'] ?? $defaultOrder['pix_auto'] ?? [],
        ];

        $credentialBySlug = GatewayCredential::forTenant($tenantId)
            ->where('is_connected', true)
            ->get()
            ->keyBy('gateway_slug');

        $methods = [];
        $methodConfig = [
            'pix' => ['id' => 'pix', 'label' => 'PIX'],
            'card' => ['id' => 'card', 'label' => 'Cartão'],
            'boleto' => ['id' => 'boleto', 'label' => 'Boleto'],
            'pix_auto' => ['id' => 'pix_auto', 'label' => 'PIX automático'],
        ];

        foreach ($methodConfig as $methodKey => $meta) {
            if ($methodKey === 'pix_auto') {
                if ($subscription === null || $subscription->gateway_subscription_id === null || $subscription->gateway_subscription_id === '') {
                    continue;
                }
            }
            $productSlug = isset($pg[$methodKey]) ? trim((string) $pg[$methodKey]) : null;
            if ($productSlug === null || $productSlug === '') {
                continue;
            }
            if ($productSlug === '__default__') {
                $slugsToCheck = is_array($order[$methodKey] ?? null) ? $order[$methodKey] : [];
            } else {
                $redundancy = $pg[$methodKey . '_redundancy'] ?? [];
                $redundancy = is_array($redundancy) ? $redundancy : [];
                $slugsToCheck = array_merge([$productSlug], $redundancy);
            }

            foreach ($slugsToCheck as $slug) {
                $cred = $credentialBySlug->get($slug);
                if (! $cred) {
                    continue;
                }
                $gateway = GatewayRegistry::get($slug);
                if (! $gateway || ! in_array($methodKey, $gateway['methods'] ?? [], true)) {
                    continue;
                }
                $methods[] = [
                    'id' => $meta['id'],
                    'label' => $meta['label'],
                    'gateway_slug' => $slug,
                    'gateway_name' => $gateway['name'] ?? $slug,
                ];
                break;
            }
        }

        return $methods;
    }
}
