<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitCustomerPaymentRequest;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\CustomerBilling;
use App\Services\CustomerPlan;
use App\Services\PaymentConfiguration;
use App\Services\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

class CustomerBillingController extends Controller
{
    public function index(Request $request, CustomerBilling $billing, CustomerPlan $plan): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'currency' => ['nullable', Rule::in(PaymentConfiguration::CURRENCIES)],
            'status' => ['nullable', Rule::in(Payments::STATUSES)], 'tab' => ['nullable', Rule::in(['all', 'review', 'approved', 'closed', 'refunds'])],
            'period' => ['nullable', Rule::in(['7', '30', '90', 'all'])], 'sort' => ['nullable', Rule::in(['newest', 'oldest'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $filters = array_replace(['q' => '', 'currency' => '', 'status' => '', 'tab' => 'all', 'period' => '30', 'sort' => 'newest'], array_filter($filters, fn ($value) => $value !== null));
        $payments = $billing->query($request->user(), $filters)->paginate(10)->withQueryString()->through(fn ($payment) => $billing->present($payment));

        return Inertia::render('account/billing', ['overview' => $plan->overview($request->user()), 'payments' => $payments,
            'filters' => $filters, 'counts' => $billing->counts($request->user()),
            'pending' => ($pending = $billing->pending($request->user())) ? $billing->present($pending) : null]);
    }

    public function options(Request $request, CustomerBilling $billing): JsonResponse
    {
        return response()->json($billing->options($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $payment, CustomerBilling $billing): JsonResponse
    {
        $record = PaymentTransaction::where('user_id', $request->user()->id)->findOrFail($payment);

        return response()->json($billing->present($record, true))->header('Cache-Control', 'private, no-store');
    }

    public function store(SubmitCustomerPaymentRequest $request, Payments $payments): JsonResponse
    {
        return response()->json($payments->submitCustomer($request->user(), $request->validated()), 201);
    }

    public function update(SubmitCustomerPaymentRequest $request, int $payment, Payments $payments): JsonResponse
    {
        PaymentTransaction::where('user_id', $request->user()->id)->findOrFail($payment);

        return response()->json($payments->submitCustomer($request->user(), $request->validated(), $payment));
    }

    public function receipt(Request $request, int $payment, string $receipt): FileResponse
    {
        $record = PaymentTransaction::where('user_id', $request->user()->id)->findOrFail($payment);
        $file = $record->receipts()->whereNull('payment_refund_id')->where('is_current', true)->findOrFail($receipt);
        abort_unless(preg_match('/^payment-receipts\/[a-f0-9-]+\.(?:png|jpe?g|webp|pdf)$/D', $file->path) && Storage::disk('local')->exists($file->path), 404);

        return response(Storage::disk('local')->get($file->path), 200, ['Content-Type' => $file->mime,
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="receipt.'.pathinfo($file->path, PATHINFO_EXTENSION).'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function methodQr(Request $request, int $method, PaymentConfiguration $configuration): FileResponse
    {
        $record = PaymentMethod::where('status', 'active')->findOrFail($method);
        abort_unless(($configuration->settings()['currencies'][$record->currency]['enabled'] ?? false) && $configuration->ready($record), 404);

        return $this->qrFile($record->qr_path);
    }

    public function paymentQr(Request $request, int $payment): FileResponse
    {
        $record = PaymentTransaction::where('user_id', $request->user()->id)->findOrFail($payment);

        return $this->qrFile($record->receiving_snapshot['receiving']['qr_path'] ?? null);
    }

    private function qrFile(?string $path): FileResponse
    {
        abort_unless($path && preg_match('/^payment-qr\/[a-f0-9-]+\.(?:png|jpe?g|webp)$/D', $path) && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => Storage::disk('local')->mimeType($path),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
