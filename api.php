<?php
/**
 * BACKEND - API.PHP (VERSÃO DEFINITIVA CORRIGIDA)
 * Configurado para EvoPay e TikTok Business API (v1.3)
 * Com Advanced Matching (E-mail e Telefone E.164) e External ID
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, API-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/* ================= CONFIGURAÇÕES ================= */

define('EVOPAY_API', 'https://pix.evopay.cash/v1/pix');
define('EVOPAY_API_KEY', 'ba4a9f7b-5a47-41ed-b66a-62da340ace16');

// URL do Webhook (Deve ser o link direto para este arquivo no seu servidor)
define('WEBHOOK_URL', 'https://test1111-1.onrender.com/api.php?webhook');

define('TRANSACTIONS_FILE', __DIR__ . '/transactions.json');

/* ===== TikTok Config ===== */
define('TIKTOK_PIXEL_ID', 'D60OFJJC77UECCBSKFH0');
define('TIKTOK_ACCESS_TOKEN', 'b690fb3500cf247f425952be5ab477edf868b074');

/* ================= FUNÇÕES AUXILIARES ================= */

function logMessage($msg, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] $msg\n";
    if ($data) $log .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $log .= str_repeat('-', 80) . "\n";
    file_put_contents(__DIR__ . '/api.log', $log, FILE_APPEND);
}

function loadTransactions() {
    if (!file_exists(TRANSACTIONS_FILE)) return [];
    $content = file_get_contents(TRANSACTIONS_FILE);
    return json_decode($content, true) ?: [];
}

function saveTransactions($data) {
    file_put_contents(TRANSACTIONS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Formata o telefone para o padrão internacional E.164 (+55...)
 */
function formatPhoneE164($phone) {
    $clean = preg_replace('/\D/', '', $phone);
    if (empty($clean)) return null;

    if (strpos($clean, '55') === 0 && (strlen($clean) == 12 || strlen($clean) == 13)) {
        return '+' . $clean;
    }

    return '+55' . $clean;
}

/**
 * Envia o evento de compra para o TikTok via API (Server-Side)
 */
function sendTikTokEvent($transaction) {
    $email_raw = strtolower(trim($transaction['email'] ?? ''));
    $phone_raw = formatPhoneE164($transaction['phone'] ?? '');

    $email_hashed = !empty($email_raw) ? hash('sha256', $email_raw) : null;
    $phone_hashed = !empty($phone_raw) ? hash('sha256', $phone_raw) : null;

    $external_id = $transaction['external_id'] ?? null;
    $external_id_hashed = !empty($external_id) ? hash('sha256', $external_id) : hash('sha256', $transaction['payerDocument'] ?? $transaction['pedido_id']);

    $user_data = [];
    if (!empty($external_id_hashed)) $user_data['external_id'] = [$external_id_hashed];
    if (!empty($email_hashed)) $user_data['email'] = [$email_hashed];
    if (!empty($phone_hashed)) $user_data['phone_number'] = [$phone_hashed];

    $payload = [
        "pixel_code" => TIKTOK_PIXEL_ID,
        "event" => "Purchase",
        "event_id" => $transaction['pedido_id'],
        "event_source" => "web",
        "event_source_id" => $transaction['pedido_id'],
        "timestamp" => date('c'),
        "context" => [
            "user" => $user_data,
            "page" => [
                "url" => "https://ps5tiktok.shop/Pagamento/pagamento.html"
            ]
        ],
        "properties" => [
            "currency" => "BRL",
            "value" => floatval($transaction['valor']),
            "contents" => [
                [
                    "content_id" => "ps5_slim",
                    "content_name" => "Console PS5 Slim 1TB",
                    "content_type" => "product",
                    "quantity" => 1,
                    "price" => floatval($transaction['valor'])
                ]
            ]
        ]
    ];

    $ch = curl_init('https://business-api.tiktok.com/open_api/v1.3/event/track/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Access-Token: ' . TIKTOK_ACCESS_TOKEN,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logMessage("TikTok Event Sent (Server-Side). Status: $http_code", json_decode($response, true));
}

/* ================= ROTAS ================= */

// WEBHOOK EVO PAY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['webhook'])) {
    $input = json_decode(file_get_contents('php://input'), true);
    logMessage('Webhook recebido da EvoPay', $input);

    if (empty($input['id']) || empty($input['status'])) {
        http_response_code(400);
        exit;
    }

    $transactions = loadTransactions();
    $evopay_id = $input['id'];

    logMessage("ID recebido no webhook: $evopay_id", $input);

    $found_key = null;
    foreach ($transactions as $key => $t) {
        if ($t['transaction_id'] == $evopay_id) {
            $found_key = $key;
            break;
        }
    }

    if (!$found_key) {
        logMessage("Transação não encontrada para ID: $evopay_id");
        http_response_code(404);
        exit;
    }

    $status = strtoupper($input['status']);
    $paid_statuses = ['COMPLETED', 'PAID', 'SUCCESS', 'CONFIRMED', 'SUCCEEDED'];

    if (in_array($status, $paid_statuses)) {
        if ($transactions[$found_key]['status'] !== 'paid') {
            $transactions[$found_key]['status'] = 'paid';
            $transactions[$found_key]['updated_at'] = date('Y-m-d H:i:s');
            saveTransactions($transactions);

            logMessage("Pagamento confirmado. Evento CompletePayment será disparado para o pedido: " . $found_key);

            // Dispara evento server-side TikTok
            sendTikTokEvent($transactions[$found_key]);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

// CONSULTA DE STATUS (FRONTEND)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pedido_id'])) {
    $transactions = loadTransactions();
    $pedido = $_GET['pedido_id'];

    if (!isset($transactions[$pedido])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'status' => $transactions[$pedido]['status'],
        'valor' => $transactions[$pedido]['valor']
    ]);
    exit;
}

// GERAÇÃO DE PIX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);

    if (empty($dados['valor']) || empty($dados['cpf'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valor e CPF são obrigatórios']);
        exit;
    }

    $nome = trim($dados['nome'] ?? 'Cliente Pix');
    $nome = preg_replace('/[^A-Za-z ]/', '', $nome);
    if (strlen($nome) < 3) $nome = 'Cliente Pix';

    $payload = [
        'amount' => floatval($dados['valor']),
        'callbackUrl' => WEBHOOK_URL,
        'payerName' => $nome,
        'payerDocument' => preg_replace('/\D/', '', $dados['cpf'])
    ];

    $ch = curl_init(EVOPAY_API);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Access-Control-Allow-Origin: *',
            'Content-Type: application/json',
            'API-Key: ' . EVOPAY_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) {
        logMessage("Erro na EvoPay. HTTP: $http", $response);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Erro ao gerar pagamento no gateway']);
        exit;
    }

    $res = json_decode($response, true);
    $transactions = loadTransactions();

    $pedido_id = $dados['pedido_id'] ?? 'PED_' . time();

    $transactions[$pedido_id] = [
        'pedido_id' => $pedido_id,
        'transaction_id' => $res['id'],
        'valor' => $dados['valor'],
        'status' => 'pending',
        'payerDocument' => preg_replace('/\D/', '', $dados['cpf']),
        'email' => $dados['email'] ?? '',
        'phone' => $dados['phone'] ?? '',
        'external_id' => $dados['external_id'] ?? null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    saveTransactions($transactions);

    echo json_encode([
        'success' => true,
        'pedido_id' => $pedido_id,
        'pixkey' => $res['qrCodeText'],
        'qrcode' => $res['qrCodeUrl'] ?? null,
        'valor' => $dados['valor']
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false]);
