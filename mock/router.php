<?php
// Local fictional upstream/downstream simulator. Never expose publicly.
header('Content-Type: application/json');
function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET' && $path === '/orders') {
    $orders = [
        ['id'=>'ORD-1001','customer_email'=>'alex@example.test','total_cents'=>12900,'created_at'=>'2026-01-10'],
        ['id'=>'ORD-1002','customer_email'=>'sam@example.test','total_cents'=>24900,'created_at'=>'2026-01-11'],
        ['id'=>'ORD-1003','customer_email'=>null,'total_cents'=>9900,'created_at'=>'2026-01-12'],
    ];
    respond(200, ['orders'=>array_values(array_filter($orders, fn($o)=>$o['created_at'] >= ($_GET['since'] ?? '')))]);
}
if ($method === 'POST' && $path === '/work-items') {
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !is_string($data['external_id'] ?? null) || $key !== 'order-'.$data['external_id'] || !filter_var($data['customer_email'] ?? null, FILTER_VALIDATE_EMAIL) || !is_int($data['total_cents'] ?? null) || $data['total_cents'] <= 0) {
        respond(422, ['message'=>'Invalid request']);
    }
    $file = getenv('MOCK_STATE') ?: sys_get_temp_dir().'/integration-demo-state.json';
    $handle = fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) { respond(503, ['message'=>'Storage unavailable']); }
    $raw = stream_get_contents($handle);
    $state = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $existing = $state[$key] ?? null;
    if ($existing) {
        fclose($handle);
        if ($existing['payload'] !== $data) { respond(409, ['message'=>'Idempotency key reused with different payload']); }
        respond(200, ['id'=>$existing['id']]);
    }
    $id = 'WORK-'.bin2hex(random_bytes(8));
    $state[$key] = ['id'=>$id,'payload'=>$data];
    rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)); fflush($handle); fclose($handle);
    respond(201, ['id'=>$id]);
}
respond(404, ['message'=>'Not found']);
