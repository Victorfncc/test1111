<?php
/**
 * BACKEND - API.PHP (VERSÃO COMPLETA TIPO PYTHON)
 * Integração EvoPay + TikTok Events API v1.3
 * Advanced Matching completo: email, telefone, nome, sobrenome, CEP, endereço, external_id
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, API-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

/* ================= CONFIGURAÇÕES ================= */
define('EVOPAY_API', 'https://pix.evopay.cash/v1/pix');
define('EVOPAY_API_KEY', 'ba4a9f7b-5a47-41ed-b66a-62da340ace16');
define('WEBHOOK_URL', 'https://transacaomarketplace.infinityfreeapp.com/api.php?webhook');
define('TRANSACTIONS_FILE', __DIR__ . '/transactions.json');

/* ===== TikTok Config ===== */
define('TIKTOK_PIXEL_ID', 'D60OFJJC77UECCBSKFH0');
define('TIKTOK_ACCESS_TOKEN', '80f36f37a6f519460fd0cf7c0a03f356008b13b6');

/* ================= FUNÇÕES AUXILIARES ================= */
function logMessage($msg, $data = null) {
    $log = "[".date('Y-m-d H:i:s')."] $msg\n";
    if($data) $log .= json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    $log .= str_repeat('-',80)."\n";
    file_put_contents(__DIR__.'/api.log',$log,FILE_APPEND);
}

function loadTransactions() {
    if(!file_exists(TRANSACTIONS_FILE)) return [];
    $content = file_get_contents(TRANSACTIONS_FILE);
    return json_decode($content,true) ?: [];
}

function saveTransactions($data) {
    file_put_contents(TRANSACTIONS_FILE,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

function formatPhoneBrazil($phone) {
    $digits = preg_replace('/\D/', '', $phone);
    if(strlen($digits) == 10 || strlen($digits) == 11) return '+55'.$digits;
    return null;
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function getAddressFromCep($cep) {
    $cep_digits = preg_replace('/\D/','',$cep);
    if(strlen($cep_digits)!=8) return null;
    $url = "https://viacep.com.br/ws/$cep_digits/json/";
    $resp = @file_get_contents($url);
    if(!$resp) return null;
    $json = json_decode($resp,true);
    if(isset($json['erro'])) return null;
    return $json;
}

/* ================= TikTok Event ================= */
function sendTikTokEvent($transaction) {
    $email = isset($transaction['email']) && isValidEmail($transaction['email']) ? $transaction['email'] : null;
    $phone = isset($transaction['phone']) ? formatPhoneBrazil($transaction['phone']) : null;
    $first_name = $transaction['first_name'] ?? null;
    $last_name = $transaction['last_name'] ?? null;
    $cep = $transaction['cep'] ?? null;
    $address_info = $cep ? getAddressFromCep($cep) : null;

    $external_id = $transaction['external_id'] ?? hash('sha256',$transaction['pedido_id']);

    $user = ['external_id'=>$external_id];
    if($email) $user['email'] = $email;
    if($phone) $user['phone_number'] = $phone;
    if($first_name) $user['first_name'] = $first_name;
    if($last_name) $user['last_name'] = $last_name;
    if($address_info) {
        $user['address'] = [
            'street'=>$address_info['logradouro']??'',
            'neighborhood'=>$address_info['bairro']??'',
            'city'=>$address_info['localidade']??'',
            'state'=>$address_info['uf']??'',
            'zip'=>$address_info['cep']??''
        ];
    }

    $content_name = $transaction['content_name'] ?? 'Produto Genérico';
    $contents = [[
        'content_id'=>hash('sha256',uniqid('',true)),
        'content_name'=>$content_name,
        'quantity'=>1,
        'price'=>floatval($transaction['valor']),
        'content_type'=>'product'
    ]];

    $event_id = $transaction['event_id'] ?? $transaction['pedido_id'].'_'.time();

    $payload = [
        'pixel_code'=>TIKTOK_PIXEL_ID,
        'event'=>$transaction['event'] ?? 'CompletePayment',
        'event_id'=>$event_id,
        'properties'=>[
            'value'=>floatval($transaction['valor']),
            'currency'=>$transaction['currency'] ?? 'BRL',
            'contents'=>$contents
        ],
        'user'=>$user
    ];

    $ch = curl_init('https://business-api.tiktok.com/open_api/v1.3/event/track/');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>[
            'Access-Token: '.TIKTOK_ACCESS_TOKEN,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS=>json_encode($payload)
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    logMessage("TikTok Event Sent (Server-Side). Status: $http_code", json_decode($response,true));
    return ['event_id'=>$event_id,'status_code'=>$http_code,'response'=>$response];
}

/* ================= ROTAS ================= */

// WEBHOOK EVO PAY
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_GET['webhook'])){
    $input = json_decode(file_get_contents('php://input'),true);
    logMessage('Webhook recebido da EvoPay',$input);

    if(empty($input['id']) || empty($input['status'])){ http_response_code(400); exit; }

    $transactions = loadTransactions();
    $evopay_id = $input['id'];
    $found_key = null;
    foreach($transactions as $key=>$t){ if($t['transaction_id']==$evopay_id){ $found_key=$key; break; } }
    if(!$found_key){ logMessage("Transação não encontrada para ID: $evopay_id"); http_response_code(404); exit; }

    $status = strtoupper($input['status']);
    if($status==='COMPLETED'||$status==='PAID'){
        if($transactions[$found_key]['status']!=='paid'){
            $transactions[$found_key]['status']='paid';
            $transactions[$found_key]['updated_at']=date('Y-m-d H:i:s');
            saveTransactions($transactions);

            $result = sendTikTokEvent($transactions[$found_key]);
            logMessage("Pagamento confirmado. Evento CompletePayment enviado para TikTok para o pedido: ".$found_key,$result);
        }
    }
    echo json_encode(['success'=>true]);
    exit;
}

// CONSULTA DE STATUS
if($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['pedido_id'])){
    $transactions = loadTransactions();
    $pedido = $_GET['pedido_id'];
    if(!isset($transactions[$pedido])){ http_response_code(404); echo json_encode(['success'=>false,'error'=>'Pedido não encontrado']); exit; }
    echo json_encode(['success'=>true,'status'=>$transactions[$pedido]['status'],'valor'=>$transactions[$pedido]['valor']]);
    exit;
}

// GERAÇÃO DE PIX
if($_SERVER['REQUEST_METHOD']==='POST'){
    $dados = json_decode(file_get_contents('php://input'),true);
    if(empty($dados['valor'])||empty($dados['cpf'])){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'Valor e CPF são obrigatórios']); exit; }

    $nome = preg_replace('/[^A-Za-z ]/','',trim($dados['nome']??'Cliente Pix'));
    if(strlen($nome)<3) $nome='Cliente Pix';

    $payload = [
        'amount'=>floatval($dados['valor']),
        'callbackUrl'=>WEBHOOK_URL,
        'payerName'=>$nome,
        'payerDocument'=>preg_replace('/\D/','',$dados['cpf'])
    ];

    $ch = curl_init(EVOPAY_API);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>[
            'Access-Control-Allow-Origin: *',
            'Content-Type: application/json',
            'API-Key: '.EVOPAY_API_KEY
        ],
        CURLOPT_POSTFIELDS=>json_encode($payload)
    ]);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($http!==200){ logMessage("Erro na EvoPay. HTTP: $http",$response); http_response_code(400); echo json_encode(['success'=>false,'error'=>'Erro ao gerar pagamento']); exit; }

    $res = json_decode($response,true);
    $transactions = loadTransactions();
    $pedido_id = $dados['pedido_id'] ?? 'PED_'.time();

    $transactions[$pedido_id]=[
        'pedido_id'=>$pedido_id,
        'transaction_id'=>$res['id'],
        'valor'=>$dados['valor'],
        'status'=>'pending',
        'payerDocument'=>preg_replace('/\D/','',$dados['cpf']),
        'email'=>$dados['email']??'',
        'phone'=>$dados['phone']??'',
        'first_name'=>$dados['first_name']??'',
        'last_name'=>$dados['last_name']??'',
        'cep'=>$dados['cep']??'',
        'content_name'=>$dados['content_name']??'Produto Genérico',
        'external_id'=>$dados['external_id']??hash('sha256',$pedido_id),
        'created_at'=>date('Y-m-d H:i:s'),
        'updated_at'=>date('Y-m-d H:i:s'),
        'event_id'=>$dados['event_id']??null
    ];

    saveTransactions($transactions);

    echo json_encode([
        'success'=>true,
        'pedido_id'=>$pedido_id,
        'pixkey'=>$res['qrCodeText'],
        'qrcode'=>$res['qrCodeUrl']??null,
        'valor'=>$dados['valor']
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success'=>false]);
