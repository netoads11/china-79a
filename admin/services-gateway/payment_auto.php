<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
//ini_set('log_errors', 1);
//ini_set('error_log', __DIR__ . '/error.log');
session_start();

include_once('../services/database.php');
include_once('../services/funcao.php');
include_once('../services/crud.php');

function sendError($msg) {
    error_log("[PAYMENT_AUTO] " . $msg . ' IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    echo $msg;
    exit;
}

function logAction($message, $data = []) {
    $log_entry = date('Y-m-d H:i:s') . " - " . $message . " - " . json_encode($data, JSON_UNESCAPED_UNICODE);
    error_log("[PAYMENT_AUTO_GATEWAY] " . $log_entry);
}

// --- Funções auxiliares ---
function formatCnpjCpf($value) {
    $CPF_LENGTH = 11;
    $cnpj_cpf = preg_replace("/\D/", '', $value);
    if (strlen($cnpj_cpf) === $CPF_LENGTH) {
        return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cnpj_cpf);
    }
    return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj_cpf);
}
function validaCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/is', '', $cpf);
    if (strlen($cpf) != 11) return false;
    if (preg_match('/(\d)\1{10}/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}
function identificarTipoChavePix($chavepix) {
    if (preg_match('/^\d{10,11}$/', $chavepix)) {
        if (validaCPF($chavepix)) return 'document';
        return 'phoneNumber';
    } elseif (preg_match('/^\d{11}$/', $chavepix)) {
        return 'document';
    } elseif (preg_match('/^\d{14}$/', $chavepix)) {
        return 'document';
    } elseif (filter_var($chavepix, FILTER_VALIDATE_EMAIL)) {
        return 'email';
    } elseif (preg_match('/^[0-9a-f]{32}$/i', $chavepix)) {
        return 'randomKey';
    }
    return 'invalid';
}

// ==================== CLASSE: MultiGatewayProcessor ====================

class MultiGatewayProcessor {
    private $mysqli;
    private $debug = [];

    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }
    public function getDebug() {
        return $this->debug;
    }

    /**
     * Buscar credenciais NextPay
     */
    public function getNextPayCredentials() {
        $sql = "SELECT * FROM nextpay WHERE ativo = 1 LIMIT 1";
        $result = $this->mysqli->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $config = $result->fetch_assoc();
            if (!empty($config['client_id']) && !empty($config['client_secret'])) {
                $this->debug[] = "NextPay: Credenciais encontradas e ativas";
                return $config;
            }
        }
        
        $this->debug[] = "NextPay: Nenhuma credencial ativa encontrada";
        return null;
    }

    /**
     * Buscar credenciais AurenPay
     */
    public function getAurenPayCredentials() {
        $sql = "SELECT * FROM aurenpay WHERE ativo = 1 LIMIT 1";
        $result = $this->mysqli->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $config = $result->fetch_assoc();
            if (!empty($config['client_id']) && !empty($config['client_secret'])) {
                $this->debug[] = "AurenPay: Credenciais encontradas e ativas";
                return $config;
            }
        }
        
        $this->debug[] = "AurenPay: Nenhuma credencial ativa encontrada";
        return null;
    }

    /**
     * Processar saque via NextPay
     * IMPORTANTE: Requer IPv4 e IP permitido na whitelist
     */
    public function processNextPay($config, $transacao_id, $valor, $chavepix, $cpf, $nome_real) {
        if (empty($config['client_id']) || empty($config['client_secret']) || empty($config['url'])) {
            return ['success' => false, 'message' => 'NextPay: Credenciais não configuradas'];
        }
    
        $api_url = rtrim($config['url'], '/');
        $this->debug[] = "NextPay: Iniciando processamento de saque (transação $transacao_id)";
    
        // Mapear tipo de chave PIX (MAIÚSCULAS conforme documentação)
        $tipo_chave = identificarTipoChavePix($chavepix);
        $pix_type_map = [
            'document' => 'CPF',
            'phoneNumber' => 'PHONE',
            'email' => 'EMAIL',
            'randomKey' => 'EVP'
        ];
        $pix_type = $pix_type_map[$tipo_chave] ?? 'CPF';
        
        // Formatar chave PIX conforme o tipo
        $pix_key_formatada = $chavepix;
        
        if ($tipo_chave === 'document') {
            // CPF/CNPJ: apenas dígitos (sem pontos/traços)
            $apenas_numeros = preg_replace('/[^0-9]/', '', $chavepix);
            $pix_type = (strlen($apenas_numeros) == 11) ? 'CPF' : 'CNPJ';
            $pix_key_formatada = $apenas_numeros;
        } elseif ($tipo_chave === 'phoneNumber') {
            // PHONE: formato E.164 (+5511999999999)
            $apenas_numeros = preg_replace('/[^0-9]/', '', $chavepix);
            
            if (strlen($apenas_numeros) === 11) {
                $pix_key_formatada = '+55' . $apenas_numeros;
            } elseif (strlen($apenas_numeros) === 13 && substr($apenas_numeros, 0, 2) === '55') {
                $pix_key_formatada = '+' . $apenas_numeros;
            } else {
                $pix_key_formatada = (strpos($chavepix, '+') === 0) ? $chavepix : '+55' . $apenas_numeros;
            }
        } elseif ($tipo_chave === 'email') {
            $pix_key_formatada = $chavepix;
        } elseif ($tipo_chave === 'randomKey') {
            $pix_key_formatada = $chavepix;
        }
        
        $this->debug[] = "NextPay: Tipo: $pix_type, Chave: $pix_key_formatada";
    
        // Limpar documento
        $documento_limpo = preg_replace('/[^0-9]/', '', $cpf);
    
        // Montar payload
        $payload = [
            'amount' => (float) $valor,
            'name' => $nome_real ?: 'Cliente',
            'document' => $documento_limpo,
            'pix_key' => $pix_key_formatada,
            'pix_type' => $pix_type,
            'webhook_url' => $GLOBALS['url_base'] . 'callbackpayment/nextpay'
        ];
    
        $headers = [
            'X-Client-Id: ' . $config['client_id'],
            'X-Client-Secret: ' . $config['client_secret'],
            'Content-Type: application/json'
        ];
    
        logAction('NextPay: Enviando requisição', [
            'url' => $api_url . '/api/requests?route=api-cashout-create',
            'payload' => $payload
        ]);
    
        // Enviar requisição com IPv4 forçado
        $response = $this->makeCurlRequest(
            $api_url . '/api/requests?route=api-cashout-create',
            $payload,
            $headers,
            'POST',
            30,
            true
        );
    
        if (!$response['success']) {
            logAction('NextPay: Erro na comunicação', $response);
            return [
                'success' => false,
                'message' => 'NextPay: Falha de comunicação - ' . ($response['message'] ?? 'sem mensagem'),
                'response' => $response
            ];
        }
    
        $data = $response['data'];
        $http_code = $response['http_code'];
        $raw_response = $response['raw_response'] ?? json_encode($data);
    
        $success_http = ($http_code >= 200 && $http_code < 300);
        
        $eh_sucesso = false;
        $e2e = null;
        $transaction_id = null;
        
        if ($success_http && isset($data['ok']) && $data['ok'] === true) {
            $eh_sucesso = true;
            $e2e = $data['e2e'] ?? null;
            $transaction_id = $data['transaction_id'] ?? $e2e ?? $transacao_id;
            
            $this->debug[] = "NextPay: Saque criado - E2E: $e2e";
        }
    
        logAction('NextPay: Resposta completa', [
            'http_code' => $http_code,
            'success' => $eh_sucesso,
            'e2e' => $e2e,
            'response_data' => $data
        ]);
    
        if ($eh_sucesso) {
            return [
                'success' => true,
                'gateway' => 'nextpay',
                'gateway_transaction_id' => $transaction_id,
                'e2e' => $e2e,
                'message' => 'Saque processado via NextPay',
                'response_data' => $data
            ];
        }
    
        $error_msg = $data['message'] ?? $data['error'] ?? 'Erro desconhecido (HTTP ' . $http_code . ')';
        
        return [
            'success' => false,
            'message' => 'NextPay: ' . $error_msg,
            'response_data' => $data,
            'raw_response' => $raw_response
        ];
    }

    /**
     * Processar saque via AurenPay
     * CORREÇÃO: Aceita HTTP 200-299 como sucesso (incluindo 201)
     */
    public function processAurenPay($config, $transacao_id, $valor, $chavepix, $cpf, $nome_real, $tipo_chave_db = null) {
        if (empty($config['client_id']) || empty($config['client_secret']) || empty($config['url'])) {
            return ['success' => false, 'message' => 'AurenPay: Credenciais não configuradas'];
        }
    
        $api_url = rtrim($config['url'], '/');
        $pix_key_type = 'evp'; // padrão
        $this->debug[] = "AurenPay: Iniciando processamento de saque (transação $transacao_id)";
    
        // ==============================
        // NORMALIZAÇÃO DO TIPO DE CHAVE
        // ==============================
        if ($tipo_chave_db) {
            $tipo_upper = strtoupper(trim($tipo_chave_db));
            if (in_array($tipo_upper, ['CPF', 'DOCUMENT'])) $pix_key_type = 'cpf';
            elseif (in_array($tipo_upper, ['CNPJ'])) $pix_key_type = 'cnpj';
            elseif (in_array($tipo_upper, ['EMAIL', 'E-MAIL'])) $pix_key_type = 'email';
            elseif (in_array($tipo_upper, ['TELEFONE', 'PHONE', 'PHONENUMBER', 'CELULAR'])) $pix_key_type = 'phone';
            elseif (in_array($tipo_upper, ['EVP', 'ALEATORIA', 'RANDOMKEY', 'RANDOM'])) $pix_key_type = 'evp';
            else {
                $tipo_detectado = identificarTipoChavePix($chavepix);
                if ($tipo_detectado === 'document') {
                    $apenas_numeros = preg_replace('/[^0-9]/', '', $chavepix);
                    $pix_key_type = (strlen($apenas_numeros) == 11) ? 'cpf' : 'cnpj';
                } elseif ($tipo_detectado === 'phoneNumber') $pix_key_type = 'phone';
                elseif ($tipo_detectado === 'email') $pix_key_type = 'email';
                else $pix_key_type = 'evp';
                $this->debug[] = "AurenPay: Tipo '$tipo_chave_db' não reconhecido, detectado como '$pix_key_type'";
            }
            $this->debug[] = "AurenPay: Tipo do banco '$tipo_chave_db' convertido para '$pix_key_type'";
        } else {
            $tipo_detectado = identificarTipoChavePix($chavepix);
            if ($tipo_detectado === 'document') {
                $apenas_numeros = preg_replace('/[^0-9]/', '', $chavepix);
                $pix_key_type = (strlen($apenas_numeros) == 11) ? 'cpf' : 'cnpj';
            } elseif ($tipo_detectado === 'phoneNumber') $pix_key_type = 'phone';
            elseif ($tipo_detectado === 'email') $pix_key_type = 'email';
            else $pix_key_type = 'evp';
            $this->debug[] = "AurenPay: Tipo detectado automaticamente como '$pix_key_type'";
        }
    
        // ==============================
        // MONTAR PAYLOAD
        // ==============================
        $documento_limpo = preg_replace('/[^0-9]/', '', $cpf);
        $value_cents = (int)($valor * 100);
        $external_id = (string)$transacao_id;
    
        $payload = [
            'external_id' => $external_id,
            'value_cents' => $value_cents,
            'receiver_name' => $nome_real ?: 'Cliente',
            'receiver_document' => $documento_limpo,
            'pix_key' => $chavepix,
            'pix_key_type' => $pix_key_type,
            'description' => 'Saque processado - ID: ' . $transacao_id,
            'postbackUrl' => $GLOBALS['url_base'] . 'callbackpayment/aurenpay'
        ];
    
        $headers = [
            'ci: ' . $config['client_id'],
            'cs: ' . $config['client_secret'],
            'Content-Type: application/json'
        ];
    
        logAction('AurenPay: Enviando requisição de saque', [
            'url' => $api_url . '/v1/pix/payment',
            'payload' => $payload,
            'headers' => $headers
        ]);
    
        // ==============================
        // ENVIAR REQUISIÇÃO
        // ==============================
        $response = $this->makeCurlRequest(
            $api_url . '/v1/pix/payment',
            $payload,
            $headers,
            'POST',
            30,
            true
        );
    
        // ==============================
        // ANÁLISE DA RESPOSTA
        // ==============================
        if (!$response['success']) {
            logAction('AurenPay: Erro na comunicação', $response);
            return [
                'success' => false,
                'message' => 'AurenPay: Falha de comunicação - ' . ($response['message'] ?? 'sem mensagem'),
                'response' => $response
            ];
        }
    
        $data = $response['data'];
        $http_code = $response['http_code'];
        $raw_response = $response['raw_response'] ?? json_encode($data);
    
        // ✅ CORREÇÃO APLICADA: Aceitar HTTP 200-299 como sucesso (incluindo 201)
        $success_http = ($http_code >= 200 && $http_code < 300);
        
        // --- Interpretação flexível ---
        $payment_data = $data['cashout'] ?? $data['data'] ?? $data ?? [];
        $reference_code = $payment_data['reference_code'] ?? $payment_data['id'] ?? $external_id;
    
        $eh_sucesso = false;
        if ($success_http) {
            if (
                (isset($payment_data['status']) && in_array(strtolower($payment_data['status']), ['success', 'approved', 'completed', 'processing'])) ||
                (isset($payment_data['message']) && stripos($payment_data['message'], 'success') !== false) ||
                isset($payment_data['reference_code'])
            ) {
                $eh_sucesso = true;
            }
        }
    
        // ==============================
        // LOG COMPLETO
        // ==============================
        logAction('AurenPay: Resposta completa', [
            'http_code' => $http_code,
            'success_detectado' => $eh_sucesso,
            'reference_code' => $reference_code,
            'response_data' => $payment_data,
            'raw' => substr($raw_response, 0, 2000)
        ]);
    
        if ($eh_sucesso) {
            return [
                'success' => true,
                'gateway' => 'aurenpay',
                'gateway_transaction_id' => $reference_code,
                'message' => 'Saque processado via AurenPay com sucesso',
                'response_data' => $payment_data
            ];
        }
    
        $error_msg = $data['message'] ?? 'Erro desconhecido (HTTP ' . $http_code . ')';
        if (isset($data['code'])) $error_msg .= ' #' . $data['code'];
    
        return [
            'success' => false,
            'message' => 'AurenPay: ' . $error_msg,
            'response_data' => $data,
            'raw_response' => $raw_response
        ];
    }

    // Credenciais BSPay com hierarquia de afiliados (mobile ou id)
    public function getBSPayCredentials($usuario_param = null) {
        if (!$usuario_param) {
            $sql = "SELECT * FROM bspay WHERE ativo = 1 LIMIT 1";
            $result = $this->mysqli->query($sql);
            if ($result && $result->num_rows > 0) {
                $this->debug[] = "BSPay: Usando primeira credencial ativa (sem usuário)";
                return $result->fetch_assoc();
            }
            return null;
        }

        // Resolve id do usuário por mobile ou id
        $usuario_id = null;
        $qry_userid = "SELECT id FROM usuarios WHERE mobile = ? OR id = ?";
        if ($stmt = $this->mysqli->prepare($qry_userid)) {
            $stmt->bind_param("si", $usuario_param, $usuario_param);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $usuario_id = $row['id'];
                $this->debug[] = "BSPay: ID do usuário encontrado: $usuario_id";
            }
            $stmt->close();
        }
        if (!$usuario_id) {
            $this->debug[] = "BSPay: Usuário não encontrado";
            return null;
        }

        // Carrega credenciais BSPay
        $sql = "SELECT * FROM bspay";
        $result = $this->mysqli->query($sql);
        $bspay_creds = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['invite_code'])) {
                    $bspay_creds[$row['invite_code']] = $row;
                }
            }
            $this->debug[] = "BSPay: Credenciais carregadas: " . count($bspay_creds);
        }

        // Sobe hierarquia de afiliados
        $current_user = $usuario_id;
        $checked_codes = [];
        $max_depth = 10;

        for ($i = 0; $i < $max_depth && $current_user; $i++) {
            $qry = "SELECT invitation_code, invite_code FROM usuarios WHERE id = ?";
            if ($stmt = $this->mysqli->prepare($qry)) {
                $stmt->bind_param("i", $current_user);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($row = $result->fetch_assoc()) {
                    $invitation_code = $row['invitation_code'];
                    $invite_code = $row['invite_code'];
                    $this->debug[] = "BSPay: Nível $i - Usuário $current_user, invitation_code: $invitation_code, invite_code: $invite_code";

                    if (!empty($invitation_code) && isset($bspay_creds[$invitation_code]) && $bspay_creds[$invitation_code]['ativo'] == 1) {
                        $this->debug[] = "BSPay: Credencial encontrada na hierarquia";
                        $stmt->close();
                        return $bspay_creds[$invitation_code];
                    }

                    if (in_array($invitation_code, $checked_codes)) {
                        $this->debug[] = "BSPay: Loop detectado na hierarquia";
                        break;
                    }
                    $checked_codes[] = $invitation_code;

                    // Sobe para o pai pelo invite_code do usuário (que aponta para invitation_code do pai)
                    $current_user = null;
                    if (!empty($invite_code)) {
                        $qry_parent = "SELECT id FROM usuarios WHERE invitation_code = ? LIMIT 1";
                        if ($stmt_parent = $this->mysqli->prepare($qry_parent)) {
                            $stmt_parent->bind_param("s", $invite_code);
                            $stmt_parent->execute();
                            $result_parent = $stmt_parent->get_result();
                            if ($row_parent = $result_parent->fetch_assoc()) {
                                $current_user = $row_parent['id'];
                            }
                            $stmt_parent->close();
                        }
                    }
                }
                $stmt->close();
            }
        }

        // Fallback
        $sql = "SELECT * FROM bspay WHERE ativo = 1 LIMIT 1";
        $result = $this->mysqli->query($sql);
        if ($result && $result->num_rows > 0) {
            $this->debug[] = "BSPay: Usando fallback (primeira ativa)";
            return $result->fetch_assoc();
        }
        return null;
    }

    // Credenciais ExpfyPay
    public function getExpfyPayCredentials() {
        $sql = "SELECT * FROM expfypay WHERE ativo = 1 LIMIT 1";
        $result = $this->mysqli->query($sql);
        if ($result && $result->num_rows > 0) {
            $config = $result->fetch_assoc();
            if (!empty($config['client_id']) && !empty($config['client_secret'])) {
                $this->debug[] = "ExpfyPay: Credenciais encontradas e ativas";
                return $config;
            }
        }
        $this->debug[] = "ExpfyPay: Nenhuma credencial ativa encontrada";
        return null;
    }

    // Processa via BSPay
    public function processBSPay($config, $transacao_id, $valor, $chavepix, $cpf, $nome_real) {
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            return ['success' => false, 'message' => 'BSPay: Credenciais não configuradas'];
        }

        $api_url = $config['url'] ?: 'https://api.pixupbr.com';
        $bearer = base64_encode($config['client_id'] . ':' . $config['client_secret']);

        // 1) Token
        $token_response = $this->makeCurlRequest(
            $api_url . '/v2/oauth/token',
            null,
            [
                'accept: application/json',
                'Authorization: Basic ' . $bearer
            ],
            'POST',
            45,
            true
        );
        if (!$token_response['success']) {
            return ['success' => false, 'message' => 'BSPay: Erro na autenticação - ' . $token_response['message']];
        }
        $token_data = $token_response['data'];
        if (!isset($token_data['access_token'])) {
            return ['success' => false, 'message' => 'BSPay: Token não retornado'];
        }

        $token = $token_data['access_token'];
        logAction('BSPay: Token obtido com sucesso');

        // 2) Pagamento
        $external_id = $transacao_id . '_bspay_' . time();
        $payment_data = [
            'creditParty' => [
                'name' => $nome_real,
                'keyType' => null,
                'key' => $chavepix,
                'taxId' => $cpf
            ],
            'amount' => number_format((float)$valor, 2, '.', ''),
            'external_id' => $external_id,
            'description' => 'Saque processado - ID: ' . $transacao_id
        ];

        logAction('BSPay: Enviando pagamento', ['external_id' => $external_id]);

        $payment_response = $this->makeCurlRequest(
            $api_url . '/v2/pix/payment',
            $payment_data,
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            'POST',
            60,
            true
        );

        if (!$payment_response['success']) {
            return ['success' => false, 'message' => 'BSPay: Erro no pagamento - ' . $payment_response['message']];
        }

        $data = $payment_response['data'];
        $http_code = $payment_response['http_code'];

        $response_gateway = $data['response'] ?? $data['status'] ?? '';
        $message = $data['message'] ?? $data['msg'] ?? '';
        $transaction_id_gateway = $data['transaction_id'] ?? $data['id'] ?? $external_id;

        $sucesso = false;
        if ($http_code >= 200 && $http_code < 300) {
            if (
                $response_gateway === 'OK' ||
                $response_gateway === 'SUCCESS' ||
                stripos($message, 'sucesso') !== false ||
                stripos($response_gateway, 'success') !== false ||
                isset($data['transaction_id'])
            ) {
                $sucesso = true;
            }
        }

        if ($sucesso) {
            logAction('BSPay: Pagamento aprovado', ['transaction_id' => $transaction_id_gateway]);
            return [
                'success' => true,
                'gateway' => 'bspay',
                'gateway_transaction_id' => $transaction_id_gateway,
                'message' => 'Saque processado via BSPay: ' . ($message ?: 'Processado com sucesso'),
                'response_data' => $data
            ];
        }

        return [
            'success' => false,
            'message' => 'BSPay: ' . ($message ?: 'Resposta inesperada'),
            'response_data' => $data
        ];
    }

    // Processa via ExpfyPay
    public function processExpfyPay($config, $transacao_id, $valor, $chavepix) {
        if (empty($config['client_id']) || empty($config['client_secret']) || empty($config['url'])) {
            return ['success' => false, 'message' => 'ExpfyPay: Não configurado corretamente'];
        }

        $tipo_chave = identificarTipoChavePix($chavepix);
        $pix_key_type_map = [
            'document' => 'CPF',
            'phoneNumber' => 'PHONE',
            'email' => 'EMAIL',
            'randomKey' => 'EVP'
        ];

        $external_id = $transacao_id . '_expfy_' . time();
        $payload = [
            "amount" => (float)$valor,
            "pix_key" => $chavepix,
            "pix_key_type" => $pix_key_type_map[$tipo_chave] ?? 'CPF',
            "description" => "Saque processado - ID: " . $transacao_id,
            "external_id" => $external_id
        ];

        $url = rtrim($config['url'], '/') . '/api/v1/withdrawls';
        $headers = [
            'X-Public-Key: ' . $config['client_id'],
            'X-Secret-Key: ' . $config['client_secret'],
            'Content-Type: application/json'
        ];

        $response = $this->makeCurlRequest($url, $payload, $headers, 'POST', 30, true);
        if (!$response['success']) {
            return $response;
        }

        $data = $response['data'];
        $http_code = $response['http_code'];
        if ($http_code >= 200 && $http_code < 300) {
            $withdrawal_id = $data['withdrawal_id'] ?? ($data['data']['withdrawal_id'] ?? ($data['id'] ?? $external_id));
            logAction('ExpfyPay: Saque aprovado', ['withdrawal_id' => $withdrawal_id]);
            return [
                'success' => true,
                'gateway' => 'expfypay',
                'gateway_transaction_id' => $withdrawal_id,
                'message' => 'Saque processado via ExpfyPay com sucesso',
                'response_data' => $data
            ];
        }

        $error_msg = $data['message'] ?? $data['error'] ?? 'Erro desconhecido';
        return [
            'success' => false,
            'message' => 'ExpfyPay: ' . $error_msg,
            'response_data' => $data
        ];
    }

    // Requisições cURL com retry e IPv4
    private function makeCurlRequest($url, $data = null, $headers = [], $method = 'POST', $timeout = 30, $force_ipv4 = true, $max_retries = 2) {
        $last_error = null;

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $curl = curl_init();
            $opts = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
            ];
            if ($force_ipv4) {
                $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                if ($data !== null) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($data);
                }
            }
            curl_setopt_array($curl, $opts);

            $response = curl_exec($curl);
            $curl_error = curl_error($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $primary_ip = curl_getinfo($curl, CURLINFO_PRIMARY_IP);
            curl_close($curl);

            if ($curl_error) {
                $last_error = [
                    'success' => false,
                    'message' => 'Erro de comunicação (tentativa ' . $attempt . '): ' . $curl_error,
                    'http_code' => $http_code
                ];
                logAction('cURL Error', ['attempt' => $attempt, 'error' => $curl_error, 'url' => $url]);
                if ($attempt < $max_retries) {
                    sleep(2);
                    continue;
                }
            } else {
                $decoded = json_decode($response, true);
                logAction('Request Success', ['ip_used' => $primary_ip, 'http_code' => $http_code]);
                return [
                    'success' => true,
                    'data' => $decoded ?: [],
                    'http_code' => $http_code,
                    'raw_response' => $response
                ];
            }
        }

        return $last_error ?: ['success' => false, 'message' => 'Falha após ' . $max_retries . ' tentativas'];
    }
}

// ==================== PROCESSAMENTO PRINCIPAL ====================

$company = 'NEXTSISTEMAS';
$owner = 'YARKAN';
$pepper = 'NXTSYS-YRK-PIX-AUTO-V1';
$secret_key = hash_hmac('sha256', $company . '|' . $owner, $pepper);

$chavepix1 = $_POST['chavepix'] ?? '';
$valor = $_POST['valor'] ?? '';
$id = $_POST['id'] ?? '';
$usuario = $_POST['usuario'] ?? '';

$assinatura = $_POST['ASSINATURA_NEXTSISTEMAS_YARKAN'] ?? '';
$gateway_preferido = $_POST['gateway'] ?? null;

if (!$chavepix1) sendError("Campo obrigatório ausente: chavepix");
if (!$valor) sendError("Campo obrigatório ausente: valor");
if (!$id) sendError("Campo obrigatório ausente: id");
if (!$assinatura) sendError("Campo obrigatório ausente: assinatura");

$payload = $chavepix1 . '|' . $valor . '|' . $id;
$assinatura_esperada = hash_hmac('sha256', $payload, $secret_key);

if (!hash_equals($assinatura_esperada, $assinatura)) {
    sendError("Requisição não autorizada: assinatura inválida.");
}

$filename = 'used_ids.json';
$used_ids = [];
if (file_exists($filename)) {
    $file_content = file_get_contents($filename);
    if ($file_content) {
        $used_ids = json_decode($file_content, true);
    }
}
if (in_array($id, $used_ids)) {
    sendError("Anti-fraude acionado: Este ID já foi usado.");
}
$used_ids[] = $id;
file_put_contents($filename, json_encode($used_ids, JSON_PRETTY_PRINT));

$valor = floatval($valor);
if ($valor <= 0) sendError("Valor inválido.");

$qry = "SELECT * FROM config WHERE id=1";
$res = $mysqli->query($qry);
$data = $res->fetch_assoc();
if ($valor > ($data['saque_automatico'] ?? 0)) {
    sendError("Valor acima do limite automático.");
}

$chavepix2 = localizarchavepix($chavepix1);
if (!$chavepix2) {
    $chavepix2 = localizarchavepix2($chavepix1);
}
$chavepix = $chavepix2['chave'] ?? $chavepix1;
$cpf = $chavepix2['cpf'] ?? '';
$nome_real = $chavepix2['realname'] ?? '';

$tipoChavePix = identificarTipoChavePix($chavepix);
if ($tipoChavePix == 'invalid') sendError("Chave Pix inválida.");

// Inicializar processador e buscar credenciais
$processor = new MultiGatewayProcessor($mysqli);

// ✅ ORDEM DE PRIORIDADE: NextPay > AurenPay > BSPay > ExpfyPay
$nextpay_cred = $processor->getNextPayCredentials();
$auren_cred = $processor->getAurenPayCredentials();
$bspay_cred = $processor->getBSPayCredentials($usuario);
$expfy_cred = $processor->getExpfyPayCredentials();

logAction('Iniciando processamento automático', [
    'transaction_id' => $id,
    'valor' => $valor,
    'usuario' => $usuario,
    'nextpay_available' => !empty($nextpay_cred),
    'aurenpay_available' => !empty($auren_cred),
    'bspay_available' => !empty($bspay_cred),
    'expfy_available' => !empty($expfy_cred),
    'gateway_preferido' => $gateway_preferido
]);

$result = null;
$errors = [];

// 1️⃣ Tentar NextPay primeiro (se disponível e preferido ou sem preferência)
if ($nextpay_cred && (!$gateway_preferido || $gateway_preferido === 'nextpay')) {
    $result = $processor->processNextPay($nextpay_cred, $id, $valor, $chavepix, $cpf, $nome_real);
    if (!$result['success']) {
        $errors['nextpay'] = $result['message'] ?? 'Falha desconhecida';
        logAction('NextPay falhou, tentando próximo gateway', ['error' => $errors['nextpay']]);
    } else {
        logAction('NextPay processou com sucesso', ['gateway_transaction_id' => $result['gateway_transaction_id']]);
    }
}

// 2️⃣ Se NextPay falhou, tentar AurenPay
if ((!$result || !$result['success']) && $auren_cred && (!$gateway_preferido || $gateway_preferido === 'aurenpay')) {
    $result = $processor->processAurenPay($auren_cred, $id, $valor, $chavepix, $cpf, $nome_real, $tipoChavePix);
    if (!$result['success']) {
        $errors['aurenpay'] = $result['message'] ?? 'Falha desconhecida';
        logAction('AurenPay falhou, tentando próximo gateway', ['error' => $errors['aurenpay']]);
    } else {
        logAction('AurenPay processou com sucesso', ['gateway_transaction_id' => $result['gateway_transaction_id']]);
    }
}

// 3️⃣ Se AurenPay falhou, tentar BSPay
if ((!$result || !$result['success']) && $bspay_cred && (!$gateway_preferido || $gateway_preferido === 'bspay')) {
    $result = $processor->processBSPay($bspay_cred, $id, $valor, $chavepix, $cpf, $nome_real);
    if (!$result['success']) {
        $errors['bspay'] = $result['message'] ?? 'Falha desconhecida';
        logAction('BSPay falhou, tentando próximo gateway', ['error' => $errors['bspay']]);
    } else {
        logAction('BSPay processou com sucesso', ['gateway_transaction_id' => $result['gateway_transaction_id']]);
    }
}

// 4️⃣ Se BSPay falhou, tentar ExpfyPay
if ((!$result || !$result['success']) && $expfy_cred) {
    $result = $processor->processExpfyPay($expfy_cred, $id, $valor, $chavepix);
    if (!$result['success']) {
        $errors['expfypay'] = $result['message'] ?? 'Falha desconhecida';
        logAction('ExpfyPay falhou', ['error' => $errors['expfypay']]);
    } else {
        logAction('ExpfyPay processou com sucesso', ['gateway_transaction_id' => $result['gateway_transaction_id']]);
    }
}

// Verificar resultado final
if (!$result || !$result['success']) {
    logAction('Falha em todos gateways', ['errors' => $errors, 'id' => $id]);
    sendError("Erro ao processar o pagamento: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
}

// Sucesso!
logAction('Pagamento automático concluído com sucesso', [
    'gateway_usado' => $result['gateway'],
    'gateway_transaction_id' => $result['gateway_transaction_id']
]);

echo "Pagamento realizado com sucesso";

if ($mysqli) {
    $mysqli->close();
}