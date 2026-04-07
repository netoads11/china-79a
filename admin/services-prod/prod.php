<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);
// Define log file path
define('PROD_LOG_FILE', dirname(__DIR__, 2) . '/errorlog.log');

function prodLog($msg) {
    $date = date('Y-m-d H:i:s');
    file_put_contents(PROD_LOG_FILE, "[$date] [PROD] $msg" . PHP_EOL, FILE_APPEND);
}

function next_sistemas_qrcode($valor, $nome, $id, $comissao = null, $afiliado_id = null, $payTypeSubListId = null, $joinBonus = true)
{
    global $mysqli;

    prodLog("Iniciando next_sistemas_qrcode. Valor: $valor, ID: $id, PayTypeSubListId: $payTypeSubListId, JoinBonus: $joinBonus");

    $resultado_aurenpay = $mysqli->query("SELECT ativo FROM aurenpay WHERE id = 1");
    $resultado_expfypay = $mysqli->query("SELECT ativo FROM expfypay WHERE id = 1");
    $resultado_bspay = $mysqli->query("SELECT ativo FROM bspay WHERE id = 1");
    $resultado_nextpay = $mysqli->query("SELECT ativo FROM nextpay WHERE id = 1");
    $resultado_versell = $mysqli->query("SELECT ativo FROM versell WHERE id = 1");
    $resultado_inpagamentos = $mysqli->query("SELECT ativo FROM inpagamentos WHERE id = 1");

    $aurenpay_coluna = $resultado_aurenpay ? $resultado_aurenpay->fetch_assoc() : ['ativo' => 0];
    $expfypay_coluna = $resultado_expfypay ? $resultado_expfypay->fetch_assoc() : ['ativo' => 0];
    $bspay_coluna = $resultado_bspay ? $resultado_bspay->fetch_assoc() : ['ativo' => 0];
    $nextpay_coluna = $resultado_nextpay ? $resultado_nextpay->fetch_assoc() : ['ativo' => 0];
    $versell_coluna = $resultado_versell ? $resultado_versell->fetch_assoc() : ['ativo' => 0];
    $inpagamentos_coluna = $resultado_inpagamentos ? $resultado_inpagamentos->fetch_assoc() : ['ativo' => 0];

    $aurenpay_ativo = $aurenpay_coluna['ativo'] ?? 0;
    $expfypay_ativo = $expfypay_coluna['ativo'] ?? 0;
    $bspay_ativo = $bspay_coluna['ativo'] ?? 0;
    $nextpay_ativo = $nextpay_coluna['ativo'] ?? 0;
    $versell_ativo = $versell_coluna['ativo'] ?? 0;
    $inpagamentos_ativo = $inpagamentos_coluna['ativo'] ?? 0;

    $tentativas = [];
    if ($versell_ativo == 1) { $tentativas[] = 'versell'; }
    if ($nextpay_ativo == 1) { $tentativas[] = 'nextpay'; }
    if ($aurenpay_ativo == 1) { $tentativas[] = 'aurenpay'; }
    if ($bspay_ativo == 1) { $tentativas[] = 'bspay'; }
    if ($expfypay_ativo == 1) { $tentativas[] = 'expfypay'; }
    if ($inpagamentos_ativo == 1) { $tentativas[] = 'inpagamentos'; }

    prodLog("Gateways ativos: " . implode(", ", $tentativas));

    // Carrega dados da Inpagamentos se estiver ativo ou for usado
    global $data_inpagamentos;
    $resInpag = $mysqli->query("SELECT * FROM inpagamentos WHERE id = 1");
    if ($resInpag && $resInpag->num_rows > 0) {
        $data_inpagamentos = $resInpag->fetch_assoc();
    }

    foreach ($tentativas as $gw) {
        prodLog("Tentando gateway: $gw");
        if ($gw === 'versell') {
            $res = criarQrVersell($valor, $nome, $id, $comissao, $afiliado_id, $payTypeSubListId, $joinBonus);
            if (!empty($res) && isset($res['transacao_id'])) { 
                prodLog("Sucesso no gateway: $gw");
                return $res; 
            }
        } elseif ($gw === 'nextpay') {
            $res = criarQrNextPay($valor, $nome, $id, $comissao, $afiliado_id, $payTypeSubListId, $joinBonus);
            if (!empty($res) && isset($res['transacao_id'])) { 
                prodLog("Sucesso no gateway: $gw");
                return $res; 
            }
        } elseif ($gw === 'aurenpay') {
            $res = criarQrAurenPay($valor, $nome, $id, $comissao, $afiliado_id, $payTypeSubListId, $joinBonus);
            if (!empty($res) && isset($res['transacao_id'])) { 
                prodLog("Sucesso no gateway: $gw");
                return $res; 
            }
        } elseif ($gw === 'bspay') {
            $res = criarQrCodePixUp($valor, $nome, $id, $comissao, $afiliado_id, null, $payTypeSubListId, $joinBonus);
            if (!empty($res) && isset($res['transacao_id'])) { 
                prodLog("Sucesso no gateway: $gw");
                return $res; 
            }
        } elseif ($gw === 'expfypay') {
            $res = criarQrexpfypay($valor, $nome, $id, $comissao, $afiliado_id, $payTypeSubListId, $joinBonus);
            if (!empty($res) && isset($res['transacao_id'])) { 
                prodLog("Sucesso no gateway: $gw");
                return $res; 
            }
        } elseif ($gw === 'inpagamentos') {
            $res = criarQrInpagamentos($valor, $nome, $id, $comissao, $afiliado_id, $payTypeSubListId, $joinBonus);
            if (!empty($res) && isset($res['transacao_id'])) { 
                prodLog("Sucesso no gateway: $gw");
                return $res; 
            }
        }
        prodLog("Falha ou retorno vazio no gateway: $gw");
    }

    prodLog("Todos os gateways falharam.");
    return null;
}

// ==================== AURENPAY ====================

function aurenPayAuth()
{
    global $data_aurenpay;
    
    return [
        'client_id' => $data_aurenpay['client_id'],
        'client_secret' => $data_aurenpay['client_secret']
    ];
}

function criarQrAurenPay($valor, $nome, $id, $comissao = null, $afiliado_id = null, $payTypeSubListId = null, $joinBonus = true)
{
    global $data_aurenpay, $url_base;
    prodLog("criarQrAurenPay: Entrada - Valor: $valor, Nome: $nome, ID: $id, Comissao: $comissao, AfiliadoID: $afiliado_id");

    $auth = aurenPayAuth();
    $url = rtrim($data_aurenpay['url'], '/') . '/v1/pix/qrcode';
    
    // Gerar external_id único
    $external_id = 'DEP-' . $id . '-' . time() . '-' . rand(1000, 9999);

    // Arrays de dados aleatórios
    $arraypix = [
        "057.033.734-84", "078.557.864-14", "094.977.774-93", 
        "033.734.824-37", "091.665.934-84", "081.299.854-54", 
        "086.861.364-94", "033.727.064-39"
    ];
    $cpf = $arraypix[array_rand($arraypix)];

    $payload = [
        "external_id" => $external_id,
        "value_cents" => (int) ($valor * 100),
        "generator_name" => $nome ?: "Cliente",
        "generator_document" => preg_replace('/[^0-9]/', '', $cpf),
        "description" => "Depósito " . $external_id,
        "postbackUrl" => $url_base . 'callbackpayment/aurenpay',
    ];

    $payloadJson = json_encode($payload);

    prodLog("[AURENPAY] Enviando requisição - External ID: $external_id, Valor: $valor, Nome: $nome");
    prodLog("[AURENPAY] Payload: " . $payloadJson);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'ci: ' . $auth['client_id'],
            'cs: ' . $auth['client_secret'],
            'Content-Type: application/json'
        ],
    ]);

    $response = curl_exec($curl);
    
    if (curl_errno($curl)) {
        $error = curl_error($curl);
        prodLog("[AURENPAY] Erro cURL: $error");
        curl_close($curl);
        return [];
    }
    
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    prodLog("[AURENPAY] Response HTTP $httpCode: $response");

    $dados = json_decode($response, true);
    $datapixreturn = [];

    // Verificar resposta de sucesso (201 Created)
    if ($httpCode == 201 && isset($dados['qrcode']['reference_code']) && isset($dados['qrcode']['content'])) {
        $reference_code = $dados['qrcode']['reference_code'];
        $qr_code_content = $dados['qrcode']['content'];
        $external_reference = $dados['qrcode']['external_reference'] ?? $external_id;

        // Gerar QR Code em base64
        $qr_code_image = generateQRCode_pix($qr_code_content);

        // Status inicial sempre pending
        $status = 'processamento';

        $insert = [
            'transacao_id' => $reference_code,
            'usuario' => $id,
            'valor' => $valor,
            'tipo' => 'deposito',
            'data_registro' => date('Y-m-d H:i:s'),
            'qrcode' => urlencode($qr_code_image),
            'status' => $status,
            'code' => $qr_code_content,
            'comissao' => $comissao,
            'afiliado_id' => $afiliado_id,
            'pay_type_sub_list_id' => $payTypeSubListId,
            'join_bonus' => $joinBonus
        ];

        $insert_paymentBD = insert_payment($insert);
        
        if ($insert_paymentBD == 1) {
            prodLog("[AURENPAY] Transação inserida com sucesso: $reference_code");
            
            $datapixreturn = [
                'transacao_id' => $reference_code,
                'transaction_id' => $reference_code,
                'external_id' => $external_reference,
                'qrcode' => urlencode($qr_code_image),
                'qr_code_image' => $qr_code_image,
                'amount' => $valor,
                'status' => $status,
                'code' => $qr_code_content
            ];
        } else {
            prodLog("[AURENPAY] Falha ao inserir transação no banco");
        }
    } else {
        prodLog("[AURENPAY] Erro na resposta da API: " . ($dados['message'] ?? 'Resposta inválida'));
    }

    return $datapixreturn;
}

// ==================== EXPFYPAY ====================

function expfypayAuth()
{
    global $data_expfypay;
    
    return [
        'public_key' => $data_expfypay['client_id'],
        'secret_key' => $data_expfypay['client_secret']
    ];
}

function criarQrexpfypay($valor, $nome, $id, $comissao = null, $afiliado_id = null, $payTypeSubListId = null, $joinBonus = true)
{
    global $data_expfypay, $url_base;
    prodLog("criarQrexpfypay: Entrada - Valor: $valor, Nome: $nome, ID: $id, Comissao: $comissao, AfiliadoID: $afiliado_id");
    $auth = expfypayAuth();
    $url = rtrim($data_expfypay['url'], '/') . '/api/v1/payments';
    $order_id = rand(11111, 99999);
    
    $arrayemail = [
        "asd4_yasmin@gmail.com", "asd4_6549498@gmail.com", "asd43_5874@gmail.com",
        "asd14_652549498@gmail.com", "asf5_654489498@gmail.com", "asd4_659749498@gmail.com",
        "asd458_78@bol.com", "ab11_2589@gmail.com"
    ];
    $email = $arrayemail[array_rand($arrayemail)];
    
    $payload = [
        "amount"      => (float) $valor,
        "description" => "Depósito " . $order_id,
        "customer"    => [
            "name"     => $nome,
            "document" => cpfRandom(0),
            "email"    => $email
        ],
        "external_id" => (string) $order_id,
        "callback_url"=> $url_base . 'callbackpayment/expfypay'
    ];
    
    $payloadJson = json_encode($payload);
    
    prodLog("[EXPFYPAY] Enviando requisição - Order ID: $order_id, Valor: $valor, Nome: $nome");
    prodLog("[EXPFYPAY] Payload: " . $payloadJson);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'X-Public-Key: ' . $auth['public_key'],
            'X-Secret-Key: ' . $auth['secret_key'],
            'Content-Type: application/json'
        ],
    ]);
    
    $response = curl_exec($curl);
    
    if (curl_errno($curl)) {
        $error = curl_error($curl);
        prodLog("[EXPFYPAY] Erro cURL: $error");
        curl_close($curl);
        return [];
    }
    
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    prodLog("[EXPFYPAY] Response HTTP $httpCode: $response");

    $dados = json_decode($response, true);
    $datapixreturn = [];
    
    if (isset($dados['success']) && $dados['success'] === true && isset($dados['data']['transaction_id'])) {
        $transaction_id = $dados['data']['transaction_id'];
        $qr_code        = $dados['data']['qr_code'];
        $qr_code_image  = $dados['data']['qr_code_image'];
        $apiStatus = strtolower(trim($dados['data']['status']));
        $status = ($apiStatus === 'completed') ? 'pago' : 'processamento';
        
        $insert = [
            'transacao_id' => $transaction_id,
            'usuario'      => $id,
            'valor'        => $valor,
            'tipo'         => 'deposito',
            'data_registro'=> date('Y-m-d H:i:s'),
            'qrcode'       => $qr_code,
            'status'       => $status,
            'code'         => $qr_code,
            'comissao'     => $comissao,
            'afiliado_id'  => $afiliado_id,
            'pay_type_sub_list_id' => $payTypeSubListId,
            'join_bonus' => $joinBonus
        ];
        
        $insert_paymentBD = insert_payment($insert);
        
        if ($insert_paymentBD == 1) {
            prodLog("[EXPFYPAY] Transação inserida com sucesso: $transaction_id");
            $datapixreturn = [
                'transacao_id'   => $transaction_id,
                'transaction_id' => $transaction_id,
                'external_id'    => $dados['data']['external_id'] ?? $order_id,
                'qrcode'         => urlencode($qr_code),
                'qr_code_image'  => $qr_code_image,
                'amount'         => $dados['data']['amount'],
                'status'         => $status,
                'code'           => $qr_code
            ];
        } else {
            prodLog("[EXPFYPAY] Falha ao inserir transação no banco");
        }
    } else {
        prodLog("[EXPFYPAY] Erro na resposta da API ou dados incompletos: " . $response);
    }
    
    return $datapixreturn;
}

function inpagamentosLog($msg) {
    prodLog("[INPAGAMENTOS] " . $msg);
}

function criarQrInpagamentos($valor, $nome, $id, $comissao = null, $afiliado_id = null, $payTypeSubListId = null, $joinBonus = true)
{
    global $data_inpagamentos, $url_base, $mysqli;
    prodLog("criarQrInpagamentos: Entrada - Valor: $valor, Nome: $nome, ID: $id, Comissao: $comissao, AfiliadoID: $afiliado_id");

    if (empty($data_inpagamentos) || empty($data_inpagamentos['public_key']) || empty($data_inpagamentos['secret_key'])) {
        inpagamentosLog("Credenciais não configuradas.");
        return [];
    }

    $url = rtrim($data_inpagamentos['url'], '/') . '/transactions';
    $external_id = 'INP-' . $id . '-' . time() . '-' . rand(1000, 9999);
    
    // Arrays de dados aleatórios
    $arraypix = [
        "057.033.734-84", "078.557.864-14", "094.977.774-93", 
        "033.734.824-37", "091.665.934-84", "081.299.854-54", 
        "086.861.364-94", "033.727.064-39"
    ];
    $cpf = $arraypix[array_rand($arraypix)];
    
    // Autenticação Basic Auth
    $auth = base64_encode($data_inpagamentos['public_key'] . ':' . $data_inpagamentos['secret_key']);

    $payload = [
        "amount" => (int)($valor * 100), // Valor em centavos
        "paymentMethod" => "pix",
        "items" => [
            [
                "title" => "Deposito",
                "quantity" => 1,
                "tangible" => false,
                "unitPrice" => (int)($valor * 100),
                "externalRef" => $external_id
            ]
        ],
        "customer" => [
            "name" => $nome ?: "Cliente",
            "email" => "cliente{$id}@email.com",
            "document" => [
                "type" => "cpf",
                "number" => preg_replace('/[^0-9]/', '', $cpf)
            ]
        ],
        "postbackUrl" => $url_base . 'callbackpayment/inpagamentos',
        "externalRef" => $external_id,
        "metadata" => json_encode(['user_id' => $id])
    ];

    $payloadJson = json_encode($payload);

    inpagamentosLog("Enviando requisição - External ID: $external_id, Valor: $valor");
    inpagamentosLog("Payload: " . $payloadJson);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
    ]);

    $response = curl_exec($curl);
    
    if (curl_errno($curl)) {
        $error = curl_error($curl);
        inpagamentosLog("Erro cURL: $error");
        curl_close($curl);
        return [];
    }
    
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    inpagamentosLog("Response HTTP $httpCode: $response");

    $dados = json_decode($response, true);
    $datapixreturn = [];

    // Verificar resposta de sucesso (200 ou 201) e presença dos dados do Pix
    if (($httpCode == 200 || $httpCode == 201) && isset($dados['pix']['qrcode'])) {
        
        $transaction_id = $dados['id']; // ID numérico da transação na Inpagamentos
        $qr_code_content = $dados['pix']['qrcode'];
        $external_reference = $dados['externalRef'] ?? $external_id;

        // Gerar QR Code em base64
        $qr_code_image = generateQRCode_pix($qr_code_content);

        // Status inicial
        $status = 'processamento';

        $insert = [
            'transacao_id' => $transaction_id,
            'usuario' => $id,
            'valor' => $valor,
            'tipo' => 'deposito',
            'data_registro' => date('Y-m-d H:i:s'),
            'qrcode' => urlencode($qr_code_image),
            'status' => $status,
            'code' => $qr_code_content,
            'comissao' => $comissao,
            'afiliado_id' => $afiliado_id,
            'pay_type_sub_list_id' => $payTypeSubListId,
            'join_bonus' => $joinBonus
        ];

        $insert_paymentBD = insert_payment($insert);
        
        if ($insert_paymentBD == 1) {
            inpagamentosLog("Transação inserida com sucesso: $transaction_id");
            
            $datapixreturn = [
                'transacao_id' => $transaction_id,
                'transaction_id' => $transaction_id,
                'external_id' => $external_reference,
                'qrcode' => urlencode($qr_code_image),
                'qr_code_image' => $qr_code_image,
                'amount' => $valor,
                'status' => $status,
                'code' => $qr_code_content
            ];
        } else {
            inpagamentosLog("Falha ao inserir transação no banco");
        }
    } else {
        inpagamentosLog("Erro na resposta da API: " . ($dados['message'] ?? 'Resposta inválida'));
    }

    return $datapixreturn;
}

// ==================== BSPAY / PIXUP ====================

function getBspayCredentialsByInviteCode($invitation_code)
{
    global $mysqli;

    if (!$invitation_code) {
        $sql = "SELECT * FROM bspay WHERE ativo = 1 LIMIT 1";
        $result = $mysqli->query($sql);
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    }

    $invite_codes = [];
    $current_code = $invitation_code;
    $max_depth = 10;
    
    while ($current_code && $max_depth-- > 0) {
        $invite_codes[] = $current_code;
        $qry = "SELECT invitation_code FROM usuarios WHERE invite_code = ? LIMIT 1";
        if ($stmt = $mysqli->prepare($qry)) {
            $stmt->bind_param("s", $current_code);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $parent_code = $row['invitation_code'];
                if ($parent_code && $parent_code !== $current_code) {
                    $current_code = $parent_code;
                } else {
                    break;
                }
            } else {
                break;
            }
            $stmt->close();
        } else {
            break;
        }
    }

    $sql = "SELECT * FROM bspay WHERE invite_code = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $invitation_code);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    $sql = "SELECT * FROM bspay";
    $result = $mysqli->query($sql);
    if (!$result) return null;
    
    $cred_fallback = null;
    while ($row = $result->fetch_assoc()) {
        if (isset($row['invite_code']) && $row['invite_code'] !== '') {
            if (in_array($row['invite_code'], $invite_codes)) {
                return $row;
            }
            if (!$cred_fallback) {
                $cred_fallback = $row;
            }
        }
    }
    
    return $cred_fallback;
}

function criarQrCodePixUp($valor, $nome, $id, $comissao = null, $afiliado_id = null, $invitation_code = null, $payTypeSubListId = null, $joinBonus = true)
{
    global $url_base, $mysqli;

    if (!is_numeric($valor) || $valor <= 0 || empty($id)) {
        return null;
    }

    $nome = trim($nome);
    if (empty($nome)) {
        $nome = 'Matheus';
    }

    if ($comissao !== null && $afiliado_id !== null) {
        prodLog("[BSPAY] Processando comissão: $comissao para afiliado: $afiliado_id");
    }

    $cred = getBspayCredentialsByInviteCode($invitation_code);
    if (!$cred || empty($cred['client_id']) || empty($cred['client_secret']) || empty($cred['url'])) {
        return null;
    }

    $transacao_id = 'SP' . random_int(100, 999) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));

    $arraypix = ["057.033.734-84", "078.557.864-14", "094.977.774-93", "033.734.824-37", "091.665.934-84", "081.299.854-54", "086.861.364-94", "033.727.064-39"];
    $cpf = $arraypix[array_rand($arraypix)];
    $arrayemail = ["asd4_yasmin@gmail.com", "asd4_6549498@gmail.com", "asd43_5874@gmail.com", "asd14_652549498@gmail.com", "asf5_654489498@gmail.com", "asd4_659749498@gmail.com", "asd458_78@bol.com", "ab11_2589@gmail.com"];
    $email = $arrayemail[array_rand($arrayemail)];

    $bearer = base64_encode($cred['client_id'] . ':' . $cred['client_secret']);
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $cred['url'] . '/v2/oauth/token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'Authorization: Basic ' . $bearer
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $bearerResponse = curl_exec($curl);
    if ($bearerResponse === false) {
        $error = curl_error($curl);
        prodLog("[BSPAY] Erro cURL Bearer: $error");
        curl_close($curl);
        return null;
    }
    $bearerData = json_decode($bearerResponse, true);
    curl_close($curl);

    if (empty($bearerData['access_token'])) {
        prodLog("[BSPAY] Falha ao obter token Bearer: " . $bearerResponse);
        return null;
    }
    $bearerToken = $bearerData['access_token'];

    $url = $cred['url'] . '/v2/pix/qrcode';
    $data = [
        'amount' => $valor,
        'external_id' => $transacao_id,
        'postbackUrl' => $url_base . 'callbackpayment/bspay',
        'payer' => [
            'name' => $nome,
            'document' => preg_replace("/[^0-9]/", "", $cpf),
            'email' => $email,
        ],
    ];

    $header = [
        'Authorization: Bearer ' . $bearerToken,
        'Content-Type: application/json',
    ];

    prodLog("[BSPAY] Enviando requisição - Valor: $valor, Nome: $nome, ID: $id");
    prodLog("[BSPAY] Payload: " . json_encode($data));

    if ($comissao !== null && $afiliado_id !== null) {
        prodLog("[BSPAY] Comissão: $comissao, Afiliado ID: $afiliado_id");
    }

    $response = enviarRequest_PAYMENT($url, $header, $data);
    prodLog("[BSPAY] Response: " . $response);

    $dados = json_decode($response, true);

    if (!isset($dados['transactionId']) || empty($dados['qrcode'])) {
        prodLog("[BSPAY] Erro na resposta da API: " . $response);
        return null;
    }

    $paymentCodeBase64 = preg_replace('/\s+/', '', generateQRCode_pix($dados['qrcode']));
    $paymentCodeBase64Encoded = urlencode($paymentCodeBase64);

    $insert = [
        'transacao_id' => $dados['transactionId'],
        'usuario' => $id,
        'valor' => $valor,
        'tipo' => 'deposito',
        'data_registro' => date('Y-m-d H:i:s'),
        'qrcode' => $paymentCodeBase64Encoded,
        'status' => 'processamento',
        'code' => $dados['qrcode'],
        'comissao' => $comissao,
        'afiliado_id' => $afiliado_id,
        'pay_type_sub_list_id' => $payTypeSubListId,
        'join_bonus' => $joinBonus
    ];
    
    $insert_paymentBD = insert_payment($insert);

    if ($insert_paymentBD == 1) {
        prodLog("[BSPAY] Transação inserida com sucesso: " . $dados['transactionId']);
        return [
            'transacao_id' => $dados['transactionId'],
            'code' => $dados['qrcode'],
            'qrcode' => $paymentCodeBase64Encoded,
            'amount' => $valor,
        ];
    } else {
        prodLog("[BSPAY] Falha ao inserir transação no banco");
        return null;
    }
}

// ==================== NEXTPAY ====================

function nextpayAuth()
{
    global $data_nextpay;
    
    return [
        'client_id' => $data_nextpay['client_id'],
        'client_secret' => $data_nextpay['client_secret']
    ];
}

function criarQrNextPay($valor, $nome, $id, $comissao = null, $afiliado_id = null, $payTypeSubListId = null, $joinBonus = true)
{
    global $data_nextpay, $url_base;
    $auth = nextpayAuth();
    $base = rtrim($data_nextpay['url'] ?? 'https://api.codexpay.app', '/');
    $arraypix = [
        "057.033.734-84", "078.557.864-14", "094.977.774-93",
        "033.734.824-37", "091.665.934-84", "081.299.854-54",
        "086.861.364-94", "033.727.064-39"
    ];
    $cpf = $arraypix[array_rand($arraypix)];
    $arrayemail = [
        "asd4_yasmin@gmail.com", "asd4_6549498@gmail.com", "asd43_5874@gmail.com",
        "asd14_652549498@gmail.com", "asf5_654489498@gmail.com", "asd4_659749498@gmail.com",
        "asd458_78@bol.com", "ab11_2589@gmail.com"
    ];
    $email = $arrayemail[array_rand($arrayemail)];
    $authPayload = json_encode([
        "client_id" => $auth['client_id'] ?? "",
        "client_secret" => $auth['client_secret'] ?? ""
    ]);
    error_log("[CODEX] Auth Request: " . json_encode(["url" => $base . "/api/auth/login", "client_id" => $auth['client_id'] ?? ""]));
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $base . '/api/auth/login',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $authPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
    ]);
    $authResponse = curl_exec($curl);
    if (curl_errno($curl)) {
        $error = curl_error($curl);
        prodLog("[CODEX] Erro cURL Auth: $error");
        error_log("[CODEX] Erro cURL Auth: " . $error);
        curl_close($curl);
        return [];
    }
    $authCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    prodLog("[CODEX] Auth HTTP $authCode: $authResponse");
    $authData = json_decode($authResponse, true);
    if (is_array($authData)) {
        $maskedAuth = $authData;
        if (isset($maskedAuth['token'])) {
            $maskedAuth['token'] = '***';
        }
        error_log("[CODEX] Auth Response: " . json_encode(["httpCode" => $authCode, "body" => $maskedAuth]));
    } else {
        error_log("[CODEX] Auth Response inválida: " . $authResponse);
    }
    $token = $authData['token'] ?? null;
    if (!$token) {
        prodLog("[CODEX] Falha autenticação");
        error_log("[CODEX] Falha autenticação, token ausente");
        return [];
    }
    $external_id = "DEP-" . $id . "-" . time() . "-" . rand(1000, 9999);
    $depositPayload = [
        "amount" => (float)$valor,
        "external_id" => $external_id,
        "clientCallbackUrl" => $url_base . 'callbackpayment/codex',
        "payer" => [
            "name" => $nome ?: "Cliente",
            "email" => $email,
            "document" => preg_replace('/[^0-9]/', '', $cpf)
        ]
    ];
    $payloadJson = json_encode($depositPayload);
    prodLog("[CODEX] Enviando depósito - External ID: $external_id, Valor: $valor");
    error_log("[CODEX] Deposit Request: " . json_encode(["url" => $base . "/api/payments/deposit", "payload" => $depositPayload]));
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $base . '/api/payments/deposit',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ],
    ]);
    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        $error = curl_error($curl);
        prodLog("[CODEX] Erro cURL Depósito: $error");
        error_log("[CODEX] Erro cURL Depósito: " . $error);
        curl_close($curl);
        return [];
    }
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    prodLog("[CODEX] Response HTTP $httpCode: $response");
    $dados = json_decode($response, true);
    if (is_array($dados)) {
        error_log("[CODEX] Deposit Response: " . json_encode(["httpCode" => $httpCode, "body" => $dados]));
    } else {
        error_log("[CODEX] Deposit Response inválida: " . $response);
    }
    $datapixreturn = [];
    $transaction_id = null;
    if (is_array($dados)) {
        $transaction_id = $dados['transaction_id'] ?? ($dados['id'] ?? ($dados['data']['transaction_id'] ?? ($dados['data']['id'] ?? null)));
        if (!$transaction_id && isset($dados['qrCodeResponse']) && is_array($dados['qrCodeResponse'])) {
            $qrBlock = $dados['qrCodeResponse'];
            $transaction_id = $qrBlock['transactionId'] ?? ($qrBlock['transaction_id'] ?? null);
        }
    }
    $qr_code_content = null;
    if (is_array($dados)) {
        $qr_code_content = $dados['pixCopiaECola'] ?? ($dados['qr_code'] ?? ($dados['qrcode'] ?? ($dados['qrCode'] ?? null)));
        if (!$qr_code_content && isset($dados['data']) && is_array($dados['data'])) {
            $qr_code_content = $dados['data']['pixCopiaECola'] ?? ($dados['data']['qr_code'] ?? ($dados['data']['qrcode'] ?? ($dados['data']['qrCode'] ?? null)));
        }
        if (!$qr_code_content && isset($dados['pix']) && is_array($dados['pix'])) {
            $qr_code_content = $dados['pix']['qr_code'] ?? ($dados['pix']['qrcode'] ?? ($dados['pix']['qrCode'] ?? null));
        }
        if (!$qr_code_content && isset($dados['qrCodeResponse']) && is_array($dados['qrCodeResponse'])) {
            $qrBlock = $dados['qrCodeResponse'];
            $qr_code_content = $qrBlock['pixCopiaECola'] ?? ($qrBlock['qr_code'] ?? ($qrBlock['qrcode'] ?? ($qrBlock['qrCode'] ?? null)));
        }
    }
    if ($transaction_id && $qr_code_content) {
        $qr_code_image = generateQRCode_pix($qr_code_content);
        $status = 'processamento';
        $insert = [
            'transacao_id' => $transaction_id,
            'usuario' => $id,
            'valor' => $valor,
            'tipo' => 'deposito',
            'data_registro' => date('Y-m-d H:i:s'),
            'qrcode' => urlencode($qr_code_image),
            'status' => $status,
            'code' => $qr_code_content,
            'comissao' => $comissao,
            'afiliado_id' => $afiliado_id,
            'pay_type_sub_list_id' => $payTypeSubListId,
            'join_bonus' => $joinBonus
        ];
        $insert_paymentBD = insert_payment($insert);
        if ($insert_paymentBD == 1) {
            $datapixreturn = [
                'transacao_id' => $transaction_id,
                'transaction_id' => $transaction_id,
                'external_id' => $external_id,
                'qrcode' => urlencode($qr_code_image),
                'qr_code_image' => $qr_code_image,
                'amount' => $valor,
                'status' => $status,
                'code' => $qr_code_content
            ];
        } else {
            prodLog("[CODEX] Falha ao inserir transação no banco");
        }
    } else {
        prodLog("[CODEX] Resposta inválida");
        error_log("[CODEX] Resposta inválida para depósito Codex");
    }
    return $datapixreturn;
}

// ==================== VERSELL ====================

function versellAuth()
{
    global $data_versell;
    return [
        'client_id' => $data_versell['client_id'] ?? '',
        'client_secret' => $data_versell['client_secret'] ?? ''
    ];
}

function criarQrVersell($valor, $nome, $id, $comissao = null, $afiliado_id = null, $payTypeSubListId = null, $joinBonus = true)
{
    global $data_versell, $url_base;

    $auth = versellAuth();
    $url = rtrim($data_versell['url'] ?? '', '/') . '/api/v1/gateway/request-qrcode';

    $arraypix = [
        "057.033.734-84", "078.557.864-14", "094.977.774-93",
        "033.734.824-37", "091.665.934-84", "081.299.854-54",
        "086.861.364-94", "033.727.064-39"
    ];
    $cpf = $arraypix[array_rand($arraypix)];

    if (empty($nome)) {
        $nome = 'Cliente Pix';
    }

    $request_number = 'VERSELL' . rand(0, 999) . '-' . date('YmdHis');
    $payload = [
        'amount' => (float) $valor,
        'requestNumber' => $request_number,
        'callbackUrl' => $url_base . 'callbackpayment/versell',
    ];

    $payloadJson = json_encode($payload);

    prodLog("[VERSELL] Enviando requisição - Valor: $valor, Nome: $nome, ID: $id, CPF: $cpf");
    prodLog("[VERSELL] Payload: " . $payloadJson);

    if ($comissao !== null && $afiliado_id !== null) {
        prodLog("[VERSELL] Comissão: $comissao, Afiliado ID: $afiliado_id");
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'vspi: ' . ($auth['client_id'] ?? ''),
            'vsps: ' . ($auth['client_secret'] ?? ''),
            'Content-Type: application/json'
        ],
    ]);

    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        $err = curl_error($curl);
        prodLog("[VERSELL] Erro cURL: $err");
        curl_close($curl);
        return [];
    }
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    prodLog("[VERSELL] Response HTTP $httpCode: $response");

    $dados = json_decode($response, true);
    $datapixreturn = [];

    $qr_code_content = null;
    $qr_code_image = null;
    $transacao_id = null;

    if (isset($dados['idTransaction']) && isset($dados['paymentCode'])) {
        $transacao_id = $dados['idTransaction'];
        $qr_code_content = $dados['paymentCode'];
        $qr_code_image = generateQRCode_pix($qr_code_content);
    } elseif (isset($dados['data']) && isset($dados['data']['idTransaction']) && isset($dados['data']['paymentCode'])) {
        $transacao_id = $dados['data']['idTransaction'];
        $qr_code_content = $dados['data']['paymentCode'];
        $qr_code_image = generateQRCode_pix($qr_code_content);
    }

    if (!$transacao_id) {
        prodLog('[VERSELL] Falha: resposta sem transacao_id');
        return [];
    }

    // Se vier imagem mas não vier o código de copiar/colar, seguimos só com a imagem
    if (empty($qr_code_image)) {
        if (!empty($qr_code_content)) {
            $qr_code_image = generateQRCode_pix($qr_code_content);
        }
    }

    // Se ainda não houver conteúdo, definimos vazio para a tela usar somente a imagem
    if ($qr_code_content === null) {
        $qr_code_content = '';
    }

    // Se a API devolveu base64 de imagem em 'qr', usamos direto
    // Caso contrário, já teremos gerado acima com o conteúdo

    $status = 'processamento';
    $insert = [
        'transacao_id' => $transacao_id,
        'usuario' => $id,
        'valor' => $valor,
        'tipo' => 'deposito',
        'data_registro' => date('Y-m-d H:i:s'),
        'qrcode' => urlencode($qr_code_image),
        'status' => $status,
        'code' => $qr_code_content,
        'comissao' => $comissao,
        'afiliado_id' => $afiliado_id,
        'pay_type_sub_list_id' => $payTypeSubListId,
        'join_bonus' => $joinBonus
    ];

    $insert_paymentBD = insert_payment($insert);
    if ($insert_paymentBD == 1) {
        prodLog("[VERSELL] Transação inserida com sucesso: $transacao_id");
        $datapixreturn = [
            'transacao_id' => $transacao_id,
            'transaction_id' => $transacao_id,
            'external_id' => $transacao_id,
            'qrcode' => urlencode($qr_code_image),
            'qr_code_image' => $qr_code_image,
            'amount' => $valor,
            'status' => $status,
            'code' => $qr_code_content
        ];
    } else {
        prodLog('[VERSELL] Falha ao inserir transação no banco');
    }

    return $datapixreturn;
}

// ==================== FUNÇÕES AUXILIARES ====================

function insert_payment($insert)
{
    global $mysqli;
    $dataarray = $insert;
    
    prodLog("insert_payment: Iniciando inserção. Dados: " . json_encode($insert));
    
    $columns = "transacao_id,usuario,valor,tipo,data_registro,qrcode,code,status";
    $placeholders = "?,?,?,?,?,?,?,?";
    $types = "ssssssss";
    $values = [
        $dataarray['transacao_id'], 
        $dataarray['usuario'], 
        $dataarray['valor'], 
        $dataarray['tipo'], 
        $dataarray['data_registro'], 
        $dataarray['qrcode'], 
        $dataarray['code'], 
        $dataarray['status']
    ];
    
    // Se houver comissão e afiliado_id, adicionar às colunas
    if (isset($dataarray['comissao']) && isset($dataarray['afiliado_id'])) {
        $columns .= ",comissao,afiliado_id";
        $placeholders .= ",?,?";
        $types .= "ss";
        $values[] = $dataarray['comissao'];
        $values[] = $dataarray['afiliado_id'];
    }

    // Se houver pay_type_sub_list_id, adicionar
    if (isset($dataarray['pay_type_sub_list_id']) && !empty($dataarray['pay_type_sub_list_id'])) {
        $columns .= ",pay_type_sub_list_id";
        $placeholders .= ",?";
        $types .= "i";
        $values[] = $dataarray['pay_type_sub_list_id'];
    }

    // Se houver join_bonus, adicionar
    if (isset($dataarray['join_bonus'])) {
        $columns .= ",join_bonus";
        $placeholders .= ",?";
        $types .= "i";
        $values[] = $dataarray['join_bonus'];
    }
    
    $sql = "INSERT INTO transacoes ($columns) VALUES ($placeholders)";
    prodLog("insert_payment: SQL: $sql");
    $stmt = $mysqli->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param($types, ...$values);
        
        if ($stmt->execute()) {
            $stmt->close();
            prodLog("insert_payment: Sucesso na inserção.");
            return 1;
        } else {
            prodLog("insert_payment: ERRO Execute: " . $stmt->error);
            $stmt->close();
            return 0;
        }
    } else {
        prodLog("insert_payment: ERRO Prepare: " . $mysqli->error);
        return 0;
    }
}

function mod($dividendo, $divisor)
{
    return round($dividendo - (floor($dividendo / $divisor) * $divisor));
}

function cpfRandom($mascara = "1")
{
    $n1 = rand(0, 9);
    $n2 = rand(0, 9);
    $n3 = rand(0, 9);
    $n4 = rand(0, 9);
    $n5 = rand(0, 9);
    $n6 = rand(0, 9);
    $n7 = rand(0, 9);
    $n8 = rand(0, 9);
    $n9 = rand(0, 9);
    $d1 = $n9 * 2 + $n8 * 3 + $n7 * 4 + $n6 * 5 + $n5 * 6 + $n4 * 7 + $n3 * 8 + $n2 * 9 + $n1 * 10;
    $d1 = 11 - (mod($d1, 11));
    if ($d1 >= 10) {
        $d1 = 0;
    }
    $d2 = $d1 * 2 + $n9 * 3 + $n8 * 4 + $n7 * 5 + $n6 * 6 + $n5 * 7 + $n4 * 8 + $n3 * 9 + $n2 * 10 + $n1 * 11;
    $d2 = 11 - (mod($d2, 11));
    if ($d2 >= 10) {
        $d2 = 0;
    }
    $retorno = '';
    if ($mascara == 1) {
        $retorno = '' . $n1 . $n2 . $n3 . "." . $n4 . $n5 . $n6 . "." . $n7 . $n8 . $n9 . "-" . $d1 . $d2;
    } else {
        $retorno = '' . $n1 . $n2 . $n3 . $n4 . $n5 . $n6 . $n7 . $n8 . $n9 . $d1 . $d2;
    }
    return $retorno;
}

?>
