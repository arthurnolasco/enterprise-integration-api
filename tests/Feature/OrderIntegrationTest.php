<?php
namespace Tests\Feature;

use App\Jobs\TransferOrder;
use App\Services\OrderIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function order(): array
    {
        return ['id'=>'ORD-1','customer_email'=>'demo@example.test','total_cents'=>1000];
    }

    public function test_preview_is_read_only(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['http://source.test/orders*'=>Http::response(['orders'=>[$this->order(), ['id'=>'ORD-2']]])]);
        $this->postJson('/api/integrations/orders/preview', ['since'=>'2026-01-01'])
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('ready', 1)->assertJsonPath('orders.1.status', 'warning');
        Queue::assertNothingPushed();
        Http::assertSentCount(1);
    }

    public function test_dispatch_queues_only_valid_orders(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['http://source.test/orders*'=>Http::response(['orders'=>[$this->order(), ['id'=>'ORD-2']]])]);
        $this->postJson('/api/integrations/orders/dispatch', ['since'=>'2026-01-01'])
            ->assertAccepted()->assertExactJson(['queued'=>1,'invalid'=>1]);
        Queue::assertPushedOn('integration', TransferOrder::class);
        Queue::assertPushed(TransferOrder::class, 1);
    }

    public function test_invalid_date_is_rejected(): void
    {
        $this->postJson('/api/integrations/orders/preview', ['since'=>'wrong'])->assertUnprocessable();
    }

    public function test_completed_order_is_not_sent_again(): void
    {
        Http::preventStrayRequests();
        Http::fake(['http://destination.test/work-items'=>Http::response(['id'=>'WORK-1'], 201)]);
        $service = app(OrderIntegration::class);
        $service->transfer($this->order());
        $service->transfer($this->order());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'order-ORD-1'));
        $this->getJson('/api/integrations/orders/ORD-1')->assertOk()->assertJsonPath('destination_id', 'WORK-1');
    }

    public function test_failed_destination_is_not_marked_completed(): void
    {
        Http::preventStrayRequests();
        Http::fake(['http://destination.test/work-items'=>Http::response([], 503)]);
        try {
            app(OrderIntegration::class)->transfer($this->order());
            $this->fail('Expected HTTP failure');
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $this->assertDatabaseCount('transfers', 0);
        }
    }
}
