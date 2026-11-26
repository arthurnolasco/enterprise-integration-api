<?php
namespace App\Services;

use App\Jobs\TransferOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OrderIntegration
{
    public function preview(string $since): array
    {
        $orders = Http::acceptJson()->connectTimeout(3)->timeout(10)
            ->get(config('integration.source_url').'/orders', ['since' => $since])
            ->throw()->json('orders');

        if (!is_array($orders) || !array_is_list($orders)) {
            throw new RuntimeException('Invalid source response.');
        }

        $rows = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                throw new RuntimeException('Invalid order record.');
            }
            $issues = [];
            if (!is_string($order['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $order['id'])) {
                $issues[] = 'Invalid order identifier';
            }
            if (!is_string($order['customer_email'] ?? null) || !filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL)) {
                $issues[] = 'Invalid customer email';
            }
            if (!is_int($order['total_cents'] ?? null) || $order['total_cents'] <= 0) {
                $issues[] = 'Invalid order total';
            }
            $rows[] = ['order' => $order, 'status' => $issues ? 'warning' : 'ready', 'issues' => $issues];
        }
        return ['total' => count($rows), 'ready' => count(array_filter($rows, fn ($row) => $row['status'] === 'ready')), 'orders' => $rows];
    }

    public function dispatch(string $since): array
    {
        $preview = $this->preview($since);
        $queued = 0;
        foreach ($preview['orders'] as $row) {
            if ($row['status'] !== 'ready') { continue; }
            $order = $row['order'];
            if (DB::table('transfers')->where('order_id', $order['id'])->where('status', 'completed')->exists()) { continue; }
            TransferOrder::dispatch($order)->onQueue('integration');
            $queued++;
        }
        return ['queued' => $queued, 'invalid' => $preview['total'] - $preview['ready']];
    }

    public function transfer(array $order): void
    {
        $id = $order['id'];
        if (DB::table('transfers')->where('order_id', $id)->where('status', 'completed')->exists()) { return; }
        $response = Http::acceptJson()->connectTimeout(3)->timeout(15)
            ->withHeaders(['Idempotency-Key' => 'order-'.$id])
            ->post(config('integration.destination_url').'/work-items', [
                'external_id' => $id,
                'customer_email' => $order['customer_email'],
                'total_cents' => $order['total_cents'],
            ])->throw()->json('id');
        if (!is_string($response) || $response === '') {
            throw new RuntimeException('Destination did not return an identifier.');
        }
        DB::table('transfers')->upsert([[
            'order_id' => $id, 'status' => 'completed', 'destination_id' => $response,
            'created_at' => now(), 'updated_at' => now(),
        ]], ['order_id'], ['status', 'destination_id', 'updated_at']);
    }
}
