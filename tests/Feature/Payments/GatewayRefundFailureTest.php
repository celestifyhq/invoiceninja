<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Feature\Payments;

use App\DataMapper\InvoiceItem;
use App\Exceptions\PaymentRefundFailed;
use App\Jobs\Util\SystemLogger;
use App\Models\Client;
use App\Models\CompanyGateway;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SystemLog;
use App\Utils\Traits\MakesHash;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Mockery;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * A refund that the gateway rejects must not be recorded locally.
 *
 * The payment, its invoices, any credits used in the payment and the
 * client balances must be left exactly as they were before the attempt.
 */
class GatewayRefundFailureTest extends TestCase
{
    use MakesHash;
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTestData();

        $this->withoutMiddleware(
            ThrottleRequests::class
        );
    }

    public function testFailedGatewayRefundLeavesPaymentInvoiceCreditAndClientUnchanged()
    {
        [$client, $invoice, $credit, $payment] = $this->createPaymentWithCredit();

        $response = [
            'transaction_reference' => '',
            'transaction_response' => '',
            'success' => false,
            'description' => 'No refundable payment found',
            'code' => 422,
            'payment_id' => $payment->id,
            'amount' => 10,
        ];

        // Drivers log the gateway failure themselves before returning.
        $this->attachGateway($payment, 10, function () use ($response, $client) {
            SystemLogger::dispatch(
                $response,
                SystemLog::CATEGORY_GATEWAY_RESPONSE,
                SystemLog::EVENT_GATEWAY_FAILURE,
                SystemLog::TYPE_STRIPE,
                $client,
                $client->company,
            );

            return $response;
        });

        $client_before = $client->fresh();

        try {
            $payment->refund($this->refundData($payment, $invoice, 50));
            $this->fail('PaymentRefundFailed was not thrown');
        } catch (PaymentRefundFailed $e) {
            $this->assertEquals('No refundable payment found', $e->getMessage());
        }

        $payment = $payment->fresh();
        $invoice = $invoice->fresh();
        $credit = $credit->fresh();
        $client = $client->fresh();

        $this->assertEquals(0, $payment->refunded);
        $this->assertEquals(Payment::STATUS_COMPLETED, $payment->status_id);
        $this->assertEquals(0, $payment->paymentables()->sum('refunded'));

        $this->assertEquals(0, $invoice->balance);
        $this->assertEquals(50, $invoice->paid_to_date);
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status_id);

        $this->assertEquals(0, $credit->balance);
        $this->assertEquals(Credit::STATUS_APPLIED, $credit->status_id);

        $this->assertEquals($client_before->balance, $client->balance);
        $this->assertEquals($client_before->paid_to_date, $client->paid_to_date);
        $this->assertEquals($client_before->credit_balance, $client->credit_balance);

        $this->assertTrue(
            SystemLog::query()
                ->where('client_id', $client->id)
                ->where('event_id', SystemLog::EVENT_GATEWAY_FAILURE)
                ->exists(),
            'The failure log written by the driver should be kept.'
        );
    }

    public function testSuccessfulGatewayRefundIsRecorded()
    {
        [$client, $invoice, $credit, $payment] = $this->createPaymentWithCredit();

        $this->attachGateway($payment, 10, fn () => $this->successResponse($payment, 10));

        $client_before = $client->fresh();

        $payment->refund($this->refundData($payment, $invoice, 50));

        $payment = $payment->fresh();
        $invoice = $invoice->fresh();
        $credit = $credit->fresh();
        $client = $client->fresh();

        $this->assertEquals(10, $payment->refunded);
        $this->assertEquals(Payment::STATUS_REFUNDED, $payment->status_id);

        $this->assertEquals(50, $invoice->balance);
        $this->assertEquals(0, $invoice->paid_to_date);

        $this->assertEquals(40, $credit->balance);
        $this->assertEquals(40, $client->credit_balance);

        $this->assertEquals($client_before->balance + 50, $client->balance);
    }

    public function testPartialRefundReturnsCreditFirstAndSendsTheRestToTheGateway()
    {
        [$client, $invoice, $credit, $payment] = $this->createPaymentWithCredit();

        // $45 refund: $40 goes back to the credit, $5 to the gateway.
        $this->attachGateway($payment, 5, fn () => $this->successResponse($payment, 5));

        $payment->refund($this->refundData($payment, $invoice, 45));

        $payment = $payment->fresh();
        $invoice = $invoice->fresh();
        $credit = $credit->fresh();
        $client = $client->fresh();

        $this->assertEquals(5, $payment->refunded);
        $this->assertEquals(Payment::STATUS_PARTIALLY_REFUNDED, $payment->status_id);

        $this->assertEquals(45, $invoice->balance);
        $this->assertEquals(5, $invoice->paid_to_date);

        $this->assertEquals(40, $credit->balance);
        $this->assertEquals(40, $client->credit_balance);
    }

    /**
     * Point the payment at a company gateway whose driver refund() runs
     * $refund, and expect exactly one gateway refund of $net_amount.
     */
    private function attachGateway(Payment $payment, float $net_amount, callable $refund): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('refund')
            ->once()
            ->with(Mockery::type(Payment::class), $net_amount)
            ->andReturnUsing($refund);

        $company_gateway = Mockery::mock(CompanyGateway::class)->makePartial();
        $company_gateway->shouldReceive('driver')->andReturn($driver);

        $payment->setRelation('company_gateway', $company_gateway);
    }

    private function successResponse(Payment $payment, float $amount): array
    {
        return [
            'transaction_reference' => 'ref_123',
            'transaction_response' => '',
            'success' => true,
            'description' => 'Refunded',
            'code' => 200,
            'payment_id' => $payment->id,
            'amount' => $amount,
        ];
    }

    private function refundData(Payment $payment, Invoice $invoice, float $amount): array
    {
        return [
            'id' => $payment->id,
            'amount' => $amount,
            'gateway_refund' => true,
            'date' => now()->format('Y-m-d'),
            'invoices' => [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                ],
            ],
        ];
    }

    /**
     * $50 invoice paid with $10 cash and a $40 credit.
     *
     * @return array{0: Client, 1: Invoice, 2: Credit, 3: Payment}
     */
    private function createPaymentWithCredit(): array
    {
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'balance' => 0,
            'paid_to_date' => 0,
            'credit_balance' => 0,
        ]);

        $item = new InvoiceItem();
        $item->cost = 50;
        $item->quantity = 1;

        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'status_id' => Invoice::STATUS_SENT,
            'amount' => 50,
            'balance' => 50,
            'line_items' => [$item],
        ]);

        $invoice->service()->markSent()->save();

        $credit_item = new InvoiceItem();
        $credit_item->cost = 40;
        $credit_item->quantity = 1;

        $credit = Credit::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'status_id' => Credit::STATUS_SENT,
            'amount' => 40,
            'balance' => 40,
            'line_items' => [$credit_item],
        ]);

        $credit->service()->markSent()->save();

        $client->credit_balance = 40;
        $client->save();

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/payments', [
            'amount' => 10,
            'client_id' => $client->hashed_id,
            'invoices' => [
                ['invoice_id' => $invoice->hashed_id, 'amount' => 50],
            ],
            'credits' => [
                ['credit_id' => $credit->hashed_id, 'amount' => 40],
            ],
            'date' => now()->format('Y-m-d'),
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(200);

        $payment = Payment::find($this->decodePrimaryKey($response->json('data.id')));

        $this->assertEquals(10, $payment->amount);
        $this->assertEquals(0, $invoice->fresh()->balance);
        $this->assertEquals(0, $credit->fresh()->balance);
        $this->assertEquals(0, $client->fresh()->credit_balance);

        return [$client, $invoice->fresh(), $credit->fresh(), $payment];
    }
}
