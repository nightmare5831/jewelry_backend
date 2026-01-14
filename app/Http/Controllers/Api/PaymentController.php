<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Create payment - Platform receives all, then auto-splits to sellers
     */
    public function createIntent(Request $request)
    {
        try {
            $request->validate([
                'order_id' => 'required|exists:orders,id',
            ]);

            $user = Auth::user();
            $order = Order::where('buyer_id', $user->id)
                ->with(['buyer', 'items.seller', 'items.product'])
                ->findOrFail($request->order_id);

            $payment = Payment::where('order_id', $order->id)->firstOrFail();

            if ($payment->status !== 'pending') {
                return response()->json(['error' => 'Payment already processed'], 400);
            }

            // Calculate fees: PIX 8%, Credit Card 10%
            $productAmount = $payment->product_amount;
            $feeRate = ($payment->payment_method === 'pix') ? 0.08 : 0.10;
            $platformFee = round($productAmount * $feeRate, 2);
            $totalAmount = $productAmount + $platformFee;

            $payment->update([
                'platform_fee' => $platformFee,
                'amount' => $totalAmount,
            ]);

            // Group items by seller and create split records
            $sellerGroups = $order->items->groupBy('seller_id');
            foreach ($sellerGroups as $sellerId => $items) {
                $seller = $items->first()->seller;
                $sellerAmount = $items->sum('total_price');

                PaymentSplit::updateOrCreate(
                    ['payment_id' => $payment->id, 'seller_id' => $sellerId],
                    [
                        'amount' => $sellerAmount,
                        'status' => 'pending',
                    ]
                );
            }

            // Create preference using PLATFORM's access token
            $accessToken = config('services.mercadopago.access_token');
            $buyer = $order->buyer;

            $preferenceData = [
                'items' => [[
                    'title' => "Order #{$order->order_number}",
                    'quantity' => 1,
                    'currency_id' => 'BRL',
                    'unit_price' => (float) $totalAmount,
                ]],
                'payer' => [
                    'name' => $buyer->name,
                    'email' => $buyer->email,
                ],
                'payment_methods' => [
                    'installments' => 12,
                    'default_installments' => 1,
                    'excluded_payment_types' => [
                        ['id' => 'ticket'],
                        ['id' => 'debit_card'],
                        ['id' => 'prepaid_card'],
                        ['id' => 'digital_currency'],
                        ['id' => 'digital_wallet'],
                    ],
                ],
                'external_reference' => (string) $order->id,
                'metadata' => [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                ],
                'notification_url' => config('app.url') . '/api/payments/webhook',
                'back_urls' => [
                    'success' => 'perfectjewel://payment-success',
                    'failure' => 'perfectjewel://payment-failure',
                    'pending' => 'perfectjewel://payment-pending',
                ],
                'auto_return' => 'approved',
                'statement_descriptor' => 'ALIANCA NOBRE',
            ];

            $response = Http::withToken($accessToken)
                ->post('https://api.mercadopago.com/checkout/preferences', $preferenceData);

            if (!$response->successful()) {
                Log::error('MercadoPago preference failed', ['response' => $response->json()]);
                return response()->json(['message' => 'Failed to create payment'], 500);
            }

            $preference = $response->json();

            $payment->update([
                'transaction_id' => $preference['id'],
                'gateway_response' => $preference,
            ]);

            Log::info('Payment preference created', [
                'preference_id' => $preference['id'],
                'order_id' => $order->id,
                'total' => $totalAmount,
                'platform_fee' => $platformFee,
                'sellers' => $sellerGroups->keys()->toArray(),
            ]);

            return response()->json([
                'preference_id' => $preference['id'],
                'init_point' => $preference['init_point'],
                'payment' => $payment,
            ]);

        } catch (\Exception $e) {
            Log::error('Payment creation failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Failed to create payment',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Webhook handler - Process payment and auto-transfer to sellers
     */
    public function webhook(Request $request)
    {
        try {
            Log::info('MercadoPago webhook received', ['data' => $request->all()]);

            if ($request->input('type') !== 'payment') {
                return response()->json(['status' => 'ignored']);
            }

            $mpPaymentId = $request->input('data.id');
            if (!$mpPaymentId) {
                return response()->json(['status' => 'error'], 400);
            }

            // Get payment details from Mercado Pago
            $accessToken = config('services.mercadopago.access_token');
            $response = Http::withToken($accessToken)
                ->get("https://api.mercadopago.com/v1/payments/{$mpPaymentId}");

            if (!$response->successful()) {
                Log::error('Failed to fetch MP payment', ['id' => $mpPaymentId]);
                return response()->json(['status' => 'error'], 400);
            }

            $mpPayment = $response->json();
            $orderId = $mpPayment['external_reference'] ?? null;

            $order = Order::find($orderId);
            if (!$order) {
                Log::error('Order not found', ['order_id' => $orderId]);
                return response()->json(['status' => 'error'], 404);
            }

            $payment = Payment::where('order_id', $order->id)->first();
            if (!$payment) {
                return response()->json(['status' => 'error'], 404);
            }

            $status = $mpPayment['status'];
            Log::info("Payment status: {$status}", ['order_id' => $orderId, 'mp_id' => $mpPaymentId]);

            if ($status === 'approved') {
                // Mark payment as completed
                $payment->markAsCompleted($mpPaymentId, $mpPayment);

                // Auto-transfer to sellers
                $this->transferToSellers($payment);

            } elseif (in_array($status, ['rejected', 'cancelled'])) {
                $payment->markAsFailed($mpPayment);
                PaymentSplit::where('payment_id', $payment->id)->update(['status' => 'failed']);
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Webhook error: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Transfer funds to sellers using Mercado Pago Payout API
     */
    private function transferToSellers(Payment $payment)
    {
        $splits = PaymentSplit::where('payment_id', $payment->id)
            ->with('seller')
            ->get();

        $accessToken = config('services.mercadopago.access_token');

        foreach ($splits as $split) {
            $seller = $split->seller;

            if (!$seller->mercadopago_user_id) {
                Log::error('Seller has no MP user_id', ['seller_id' => $seller->id]);
                $split->update(['status' => 'failed']);
                continue;
            }

            try {
                // Create transfer to seller using Payout API
                $response = Http::withToken($accessToken)
                    ->post('https://api.mercadopago.com/v1/transaction_intentions/process', [
                        'external_id' => "split_{$split->id}",
                        'point_of_interaction' => 'application',
                        'destination_account' => [
                            'owner' => [
                                'identification' => [
                                    'type' => 'ID',
                                    'number' => $seller->mercadopago_user_id,
                                ],
                            ],
                        ],
                        'transaction' => [
                            'from' => 'account_money',
                            'total_amount' => (float) $split->amount,
                            'currency_id' => 'BRL',
                        ],
                    ]);

                if ($response->successful()) {
                    $split->update(['status' => 'completed']);
                    Log::info('Transfer to seller completed', [
                        'seller_id' => $seller->id,
                        'amount' => $split->amount,
                    ]);
                } else {
                    // Try alternative: Send money via user_id
                    $altResponse = Http::withToken($accessToken)
                        ->post('https://api.mercadopago.com/v1/account/bank_report/payout', [
                            'user_id' => (int) $seller->mercadopago_user_id,
                            'amount' => (float) $split->amount,
                            'external_reference' => "split_{$split->id}",
                        ]);

                    if ($altResponse->successful()) {
                        $split->update(['status' => 'completed']);
                        Log::info('Transfer to seller completed (alt)', [
                            'seller_id' => $seller->id,
                            'amount' => $split->amount,
                        ]);
                    } else {
                        $split->update(['status' => 'pending_manual']);
                        Log::warning('Auto-transfer failed, marked for manual', [
                            'seller_id' => $seller->id,
                            'amount' => $split->amount,
                            'response' => $response->json(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Transfer to seller failed', [
                    'seller_id' => $seller->id,
                    'error' => $e->getMessage(),
                ]);
                $split->update(['status' => 'pending_manual']);
            }
        }
    }

    /**
     * Get payment status
     */
    public function status($id)
    {
        $payment = Payment::with(['order', 'splits.seller'])
            ->whereHas('order', fn($q) => $q->where('buyer_id', Auth::id()))
            ->findOrFail($id);

        return response()->json($payment);
    }

    /**
     * Retry failed payment
     */
    public function retry($id)
    {
        $payment = Payment::with('order')
            ->whereHas('order', fn($q) => $q->where('buyer_id', Auth::id()))
            ->findOrFail($id);

        if ($payment->status !== 'failed') {
            return response()->json(['error' => 'Only failed payments can be retried'], 400);
        }

        $payment->update(['status' => 'pending']);
        PaymentSplit::where('payment_id', $payment->id)->update(['status' => 'pending']);

        return response()->json(['message' => 'Payment retry initiated', 'payment' => $payment]);
    }
}
