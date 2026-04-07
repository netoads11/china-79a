<?php
/**
 * PAYMENT MANUAL - Multi Gateway (NextPay + AurenPay + BSPay + ExpfyPay)
 * Processamento de saques PIX com múltiplos gateways e hierarquia de afiliados
 * Versão com IPv4 forçado e retry automático
 * 
 * ATUALIZAÇÃO: Adicionado suporte para NextPay como gateway prioritário
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
//ini_set('log_errors', 1);
//ini_set('error_log', __DIR__ . '/error.log');

session_start();

// Includes necessários
include_once('../services/database.php');
include_once('../services/funcao.php');
include_once('../services/crud.php');

function validar_2fa_admin($codigo_2fa){
    global $mysqli;
    if(empty($_SESSION['data_adm']['id'])) return false;
    $admin_id = intval($_SESSION['data_adm']['id']);
    $qry = $mysqli->prepare("SELECT `2fa` FROM admin_users WHERE id = ?");
    $qry->bind_param("i", $admin_id);
    $qry->execute();
    $res = $qry->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if(!$row || empty($row['2fa'])) return false;
    $stored = $row['2fa'];
    $codigo_2fa = trim($codigo_2fa);
    if(strlen($stored) >= 60){
        return password_verify($codigo_2fa, $stored);
    }
    return hash_equals($stored, $codigo_2fa);
}
$codigo2fa = $_GET['codigo_2fa'] ?? $_POST['codigo_2fa'] ?? '';
if($codigo2fa === ''){
    header('Content-Type: application/json'); http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Código 2FA obrigatório']); exit;
}
if(!validar_2fa_admin($codigo2fa)){
    header('Content-Type: application/json'); http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Código 2FA inválido']); exit;
}

// ==================== FUNÇÕES AUXILIARES ====================

function formatCnpjCpf($value)
{
    $CPF_LENGTH = 11;
    $cnpj_cpf = preg_replace("/\D/", '', $value);

    if (strlen($cnpj_cpf) === $CPF_LENGTH) {
        return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cnpj_cpf);
    }

    return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj_cpf);
}

function validaCPF($cpf)
{
    $cpf = preg_replace('/[^0-9]/is', '', $cpf);

    if (strlen($cpf) != 11) {
        return false;
    }

    if (preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }

    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }

        $d = ((10 * $d) % 11) % 10;

        if ($cpf[$c] != $d) {
            return false;
        }
    }

    return true;
}

function identificarTipoChavePix($chavepix)
{
    if (preg_match('/^\d{10,11}$/', $chavepix)) {
        if (validaCPF($chavepix)) {
            return 'document';
        }
        return 'phoneNumber';
    } elseif (preg_match('/^\d{11}$/', $chavepix)) {
        return 'document';
    } elseif (preg_match('/^\d{14}$/', $chavepix)) {
        return 'document';
    } elseif (filter_var($chavepix, FILTER_VALIDATE_EMAIL)) {
        return 'email';
    } elseif (preg_match('/^[0-9a-f]{32}$/i', $chavepix)) {
        return 'randomKey';
    } else {
        return 'invalid';
    }
}

function logAction($message, $data = [])
{
    $log_entry = date('Y-m-d H:i:s') . " - " . $message . " - " . json_encode($data, JSON_UNESCAPED_UNICODE);
    error_log("[PAYMENT_MULTI_GATEWAY] " . $log_entry);
}

function sendResponse($success, $message, $data = null, $http_code = 200)
{
    http_response_code($http_code);
    
    $response = [
        "success" => $success,
        "message" => $message,
        "timestamp" => date('c')
    ];
    
    if ($data !== null) {
        $response["data"] = $data;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// ==================== CLASSE PARA PROCESSAMENTO DE GATEWAYS ====================

class MultiGatewayProcessor
{
    private $mysqli;
    private $debug = [];
    
    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
    }
    
    public function getDebug()
    {
        return $this->debug;
    }
    
    /**
     * Buscar credenciais NextPay
     */
    public function getNextPayCredentials()
    {
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
    public function getAurenPayCredentials()
    {
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
     * Buscar credenciais BSPay com hierarquia de afiliados
     */
    public function getBSPayCredentials($usuario_param = null)
    {
        if (!$usuario_param) {
            // Sem usuário, pega primeira credencial ativa
            $sql = "SELECT * FROM bspay WHERE ativo = 1 LIMIT 1";
            $result = $this->mysqli->query($sql);
            if ($result && $result->num_rows > 0) {
                $this->debug[] = "BSPay: Usando primeira credencial ativa (sem usuário)";
                return $result->fetch_assoc();
            }
            return null;
        }
        
        // Buscar ID do usuário
        $usuario_id = null;
        $qry_userid = "SELECT id FROM usuarios WHERE mobile = ? OR id = ?";
        if ($stmt = $this->mysqli->prepare($qry_userid)) {
            $stmt->bind_param("si", $usuario_param, $usuario_param);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $usuario_id = $row['id'];
                $this->debug[] = "BSPay: ID do usuário encontrado: $usuario_id";
            }
            $stmt->close();
        }
        
        if (!$usuario_id) {
            $this->debug[] = "BSPay: Usuário não encontrado";
            return null;
        }
        
        // Carregar todas credenciais BSPay
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
        
        // Subir hierarquia de afiliados
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
                    $this->debug[] = "BSPay: Nível $i - Usuário $current_user, invitation_code: $invitation_code";
                    
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
                    
                    // Subir para o pai
                    $current_user = null;
                    if (!empty($row['invite_code'])) {
                        $qry_parent = "SELECT id FROM usuarios WHERE invite_code = ? LIMIT 1";
                        if ($stmt_parent = $this->mysqli->prepare($qry_parent)) {
                            $stmt_parent->bind_param("s", $row['invitation_code']);
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
        
        // Fallback: primeira credencial ativa
        $sql = "SELECT * FROM bspay WHERE ativo = 1 LIMIT 1";
        $result = $this->mysqli->query($sql);
        if ($result && $result->num_rows > 0) {
            $this->debug[] = "BSPay: Usando primeira credencial ativa (fallback)";
            return $result->fetch_assoc();
        }
        
        $this->debug[] = "BSPay: Nenhuma credencial disponível";
        return null;
    }
    
    /**
     * Buscar credenciais ExpfyPay
     */
    public function getExpfyPayCredentials()
    {
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
    
    /**
     * Processar saque via NextPay
     * IMPORTANTE: Requer IPv4 e IP permitido na whitelist
     */
    public function processNextPay($config, $transacao_id, $valor, $chavepix, $cpf, $nome_real)
    {
        if (empty($config['client_id']) || empty($config['client_secret']) || empty($config['url'])) {
            return ['success' => false, 'message' => 'NextPay: Credenciais não configuradas'];
        }
    
        $api_url = rtrim($config['url'], '/');
        $this->debug[] = "NextPay: Iniciando processamento de saque (transação $transacao_id)";
    
        // Mapear tipo de chave PIX (MAIÚSCULAS conforme documentação)
        $tipo_chave = identificarTipoChavePix($chavepix);
        $pix_type_map = [
            'document' => 'CPF',      // Será ajustado para CNPJ se necessário
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
            // PHONE: formato E.164 (+5511999999999) ou apenas dígitos com DDI+DDD
            $apenas_numeros = preg_replace('/[^0-9]/', '', $chavepix);
            
            // Se não começar com código do país, adicionar +55 (Brasil)
            if (strlen($apenas_numeros) === 11) {
                // Formato: 11999999999 -> +5511999999999
                $pix_key_formatada = '+55' . $apenas_numeros;
            } elseif (strlen($apenas_numeros) === 13 && substr($apenas_numeros, 0, 2) === '55') {
                // Formato: 5511999999999 -> +5511999999999
                $pix_key_formatada = '+' . $apenas_numeros;
            } else {
                // Usar como está se já tiver formato adequado
                $pix_key_formatada = (strpos($chavepix, '+') === 0) ? $chavepix : '+55' . $apenas_numeros;
            }
        } elseif ($tipo_chave === 'email') {
            // EMAIL: usar como está (já validado)
            $pix_key_formatada = $chavepix;
        } elseif ($tipo_chave === 'randomKey') {
            // EVP: chave aleatória (32 hex) ou UUID - usar como está
            $pix_key_formatada = $chavepix;
        }
        
        $this->debug[] = "NextPay: Tipo de chave: $pix_type, Chave formatada: $pix_key_formatada";
    
        // Limpar documento (CPF/CNPJ do beneficiário - apenas dígitos)
        $documento_limpo = preg_replace('/[^0-9]/', '', $cpf);
    
        // Montar payload
        $payload = [
            'amount' => (float) $valor,
            'name' => $nome_real ?: 'Cliente',
            'document' => $documento_limpo,
            'pix_key' => $pix_key_formatada,
            'pix_type' => $pix_type, // MAIÚSCULAS: EMAIL, CPF, CNPJ, PHONE, EVP
            'webhook_url' => $GLOBALS['url_base'] . 'callbackpayment/nextpay'
        ];
    
        $headers = [
            'X-Client-Id: ' . $config['client_id'],
            'X-Client-Secret: ' . $config['client_secret'],
            'Content-Type: application/json'
        ];
    
        logAction('NextPay: Enviando requisição de saque', [
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
            true // Forçar IPv4
        );
    
        // Análise da resposta
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
    
        // Verificar sucesso
        $success_http = ($http_code >= 200 && $http_code < 300);
        
        // Verificar resposta da API
        $eh_sucesso = false;
        $e2e = null;
        $transaction_id = null;
        
        if ($success_http && isset($data['ok']) && $data['ok'] === true) {
            $eh_sucesso = true;
            $e2e = $data['e2e'] ?? null;
            $transaction_id = $data['transaction_id'] ?? $e2e ?? $transacao_id;
            
            $this->debug[] = "NextPay: Saque criado com sucesso - E2E: $e2e";
        }
    
        // Log completo
        logAction('NextPay: Resposta completa', [
            'http_code' => $http_code,
            'success_detectado' => $eh_sucesso,
            'e2e' => $e2e,
            'response_data' => $data,
            'raw' => substr($raw_response, 0, 2000)
        ]);
    
        if ($eh_sucesso) {
            return [
                'success' => true,
                'gateway' => 'nextpay',
                'gateway_transaction_id' => $transaction_id,
                'e2e' => $e2e,
                'message' => 'Saque processado via NextPay com sucesso',
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
     * CORREÇÃO: Agora aceita HTTP 200-299 como sucesso (incluindo 201)
     */
    public function processAurenPay($config, $transacao_id, $valor, $chavepix, $cpf, $nome_real, $tipo_chave_db = null)
    {
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
        $external_id = (string)$transacao_id; // usar ID original para rastreabilidade
    
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
    
    /**
     * Processar via BSPay
     * CORREÇÃO: Aceita HTTP 200-299 como sucesso
     */
    public function processBSPay($config, $transacao_id, $valor, $chavepix, $cpf, $nome_real)
    {
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            return ['success' => false, 'message' => 'BSPay: Credenciais não configuradas'];
        }
        
        $api_url = $config['url'] ?: 'https://api.pixupbr.com';
        $bearer = base64_encode($config['client_id'] . ':' . $config['client_secret']);
        
        logAction('BSPay: Iniciando autenticação', ['api_url' => $api_url]);
        
        // 1. Obter token
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
            return [
                'success' => false,
                'message' => 'BSPay: Erro na autenticação - ' . $token_response['message']
            ];
        }
        
        $token_data = $token_response['data'];
        if (!isset($token_data['access_token'])) {
            return [
                'success' => false,
                'message' => 'BSPay: Token não retornado'
            ];
        }
        
        $token = $token_data['access_token'];
        logAction('BSPay: Token obtido com sucesso');
        
        // 2. Processar pagamento
        $external_id = $transacao_id . '_bspay_' . time();
        $tipo_chave = identificarTipoChavePix($chavepix);
        
        $payment_data = [
            'creditParty' => [
                'name' => $nome_real,
                'keyType' => null,
                'key' => $chavepix,
                'taxId' => $cpf
            ],
            'amount' => number_format((float) $valor, 2, '.', ''),
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
            return [
                'success' => false,
                'message' => 'BSPay: Erro no pagamento - ' . $payment_response['message']
            ];
        }
        
        $data = $payment_response['data'];
        $http_code = $payment_response['http_code'];
        
        // ✅ CORREÇÃO: Verificar sucesso com HTTP 200-299
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
    
    /**
     * Processar via ExpfyPay
     * CORREÇÃO: Aceita HTTP 200-299 como sucesso
     */
    public function processExpfyPay($config, $transacao_id, $valor, $chavepix)
    {
        if (empty($config['client_id']) || empty($config['client_secret']) || empty($config['url'])) {
            return ['success' => false, 'message' => 'ExpfyPay: Não configurado corretamente'];
        }
        
        // Mapear tipo de chave
        $tipo_chave = identificarTipoChavePix($chavepix);
        $pix_key_type_map = [
            'document' => 'CPF',
            'phoneNumber' => 'PHONE',
            'email' => 'EMAIL',
            'randomKey' => 'EVP'
        ];
        
        $external_id = $transacao_id . '_expfy_' . time();
        
        $payload = [
            "amount" => (float) $valor,
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
        
        logAction('ExpfyPay: Enviando saque', ['external_id' => $external_id]);
        
        $response = $this->makeCurlRequest($url, $payload, $headers, 'POST', 30, true);
        
        if (!$response['success']) {
            return $response;
        }
        
        $data = $response['data'];
        $http_code = $response['http_code'];
        
        // ✅ CORREÇÃO: Aceita HTTP 200-299 como sucesso
        if ($http_code >= 200 && $http_code < 300) {
            $withdrawal_id = $data['withdrawal_id'] ?? $data['data']['withdrawal_id'] ?? $data['id'] ?? $external_id;
            
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
    
    /**
     * Fazer requisição cURL com retry e IPv4 forçado
     */
    private function makeCurlRequest($url, $data = null, $headers = [], $method = 'POST', $timeout = 30, $force_ipv4 = true, $max_retries = 2)
    {
        $last_error = null;
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $curl = curl_init();
            
            $curl_options = [
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
            
            // Forçar IPv4
            if ($force_ipv4) {
                $curl_options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
            
            if ($method === 'POST') {
                $curl_options[CURLOPT_POST] = true;
                if ($data) {
                    $curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
                }
            }
            
            curl_setopt_array($curl, $curl_options);
            
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
                
                logAction('cURL Error', [
                    'attempt' => $attempt,
                    'error' => $curl_error,
                    'url' => $url
                ]);
                
                if ($attempt < $max_retries) {
                    sleep(2);
                    continue;
                }
            } else {
                $decoded = json_decode($response, true);
                
                logAction('Request Success', [
                    'ip_used' => $primary_ip,
                    'http_code' => $http_code
                ]);
                
                return [
                    'success' => true,
                    'data' => $decoded ?: [],
                    'http_code' => $http_code,
                    'raw_response' => $response
                ];
            }
        }
        
        return $last_error ?: [
            'success' => false,
            'message' => 'Falha após ' . $max_retries . ' tentativas'
        ];
    }
}

// ==================== PROCESSAMENTO PRINCIPAL ====================

// Verificar se ID foi fornecido
if (!isset($_GET['id'])) {
    sendResponse(false, "ID da transação não informado", null, 400);
}

$id = PHP_SEGURO($_GET['id']);
$usuario_param = isset($_GET['usuario']) ? PHP_SEGURO($_GET['usuario']) : null;

// Buscar dados do saque
$sql = "SELECT valor, pix, tipo FROM solicitacao_saques WHERE transacao_id = ?";
if (!$stmt = $mysqli->prepare($sql)) {
    sendResponse(false, "Erro ao preparar consulta", null, 500);
}

$stmt->bind_param("s", $id);
$stmt->execute();
$stmt->bind_result($valor, $chavepix1, $tipo_chave_db);
$stmt->fetch();
$stmt->close();

if (!$valor || !$chavepix1) {
    sendResponse(false, "Saque não encontrado", null, 404);
}

// Obter informações da chave PIX
$chavepix2 = localizarchavepix($chavepix1);
$chavepix = $chavepix2['chave'];
$cpf = $chavepix2['cpf'];
$nome_real = $chavepix2['realname'];

// Validar chave PIX
$tipoChavePix = identificarTipoChavePix($chavepix);
if ($tipoChavePix === 'invalid') {
    sendResponse(false, "Chave PIX inválida", null, 400);
}

// Inicializar processador
$processor = new MultiGatewayProcessor($mysqli);

// Buscar credenciais dos gateways (ordem de prioridade: NextPay > AurenPay > BSPay > ExpfyPay)
$nextpay_cred = $processor->getNextPayCredentials();
$auren_cred = $processor->getAurenPayCredentials();
$bspay_cred = $processor->getBSPayCredentials($usuario_param);
$expfy_cred = $processor->getExpfyPayCredentials();

$debug_info = $processor->getDebug();

// Verificar se há pelo menos um gateway configurado
if (!$nextpay_cred && !$auren_cred && !$bspay_cred && !$expfy_cred) {
    sendResponse(false, "Nenhum gateway de pagamento configurado", ['debug' => $debug_info], 500);
}

logAction('Iniciando processamento', [
    'transaction_id' => $id,
    'valor' => $valor,
    'usuario' => $usuario_param,
    'nextpay_available' => !empty($nextpay_cred),
    'aurenpay_available' => !empty($auren_cred),
    'bspay_available' => !empty($bspay_cred),
    'expfy_available' => !empty($expfy_cred)
]);

// Tentar processar com os gateways disponíveis
$result = null;
$gateway_usado = null;
$errors = [];

// Preferir gateway especificado
$gateway_preferido = $_GET['gateway'] ?? null;

// 1️⃣ Tentar NextPay primeiro (se disponível e preferido ou se for o prioritário)
if ($nextpay_cred && (!$gateway_preferido || $gateway_preferido === 'nextpay')) {
    $result = $processor->processNextPay($nextpay_cred, $id, $valor, $chavepix, $cpf, $nome_real);
    
    if ($result['success']) {
        $gateway_usado = 'nextpay';
    } else {
        $errors['nextpay'] = $result['message'];
    }
}

// 2️⃣ Se NextPay falhou, tentar AurenPay
if ((!$result || !$result['success']) && $auren_cred && (!$gateway_preferido || $gateway_preferido === 'aurenpay')) {
    $result = $processor->processAurenPay($auren_cred, $id, $valor, $chavepix, $cpf, $nome_real, $tipo_chave_db);
    
    if ($result['success']) {
        $gateway_usado = 'aurenpay';
    } else {
        $errors['aurenpay'] = $result['message'];
    }
}

// 3️⃣ Se AurenPay falhou, tentar BSPay
if ((!$result || !$result['success']) && $bspay_cred && (!$gateway_preferido || $gateway_preferido === 'bspay')) {
    $result = $processor->processBSPay($bspay_cred, $id, $valor, $chavepix, $cpf, $nome_real);
    
    if ($result['success']) {
        $gateway_usado = 'bspay';
    } else {
        $errors['bspay'] = $result['message'];
    }
}

// 4️⃣ Se BSPay falhou, tentar ExpfyPay
if ((!$result || !$result['success']) && $expfy_cred) {
    $result = $processor->processExpfyPay($expfy_cred, $id, $valor, $chavepix);
    
    if ($result['success']) {
        $gateway_usado = 'expfypay';
    } else {
        $errors['expfypay'] = $result['message'];
    }
}

// Verificar se conseguiu processar
if (!$result || !$result['success']) {
    logAction('Falha em todos os gateways', ['errors' => $errors]);
    
    sendResponse(false, "Falha ao processar pagamento em todos os gateways", [
        'errors' => $errors,
        'debug' => $debug_info
    ], 500);
}

// Atualizar banco de dados
$sql_update = "UPDATE solicitacao_saques SET status = 1, data_att = CONVERT_TZ(NOW(), '+00:00', '-03:00') WHERE transacao_id = ?";

if (!$stmt_update = $mysqli->prepare($sql_update)) {
    sendResponse(false, "Erro ao preparar atualização do banco", null, 500);
}

$stmt_update->bind_param("s", $id);
$stmt_update->execute();

if ($stmt_update->affected_rows > 0) {
    $stmt_update->close();
    
    logAction('Pagamento processado com sucesso', [
        'transaction_id' => $id,
        'gateway' => $gateway_usado,
        'gateway_transaction_id' => $result['gateway_transaction_id']
    ]);
    
    sendResponse(true, "Saque aprovado com sucesso via " . strtoupper($gateway_usado), [
        'transaction_id' => $id,
        'gateway_transaction_id' => $result['gateway_transaction_id'],
        'gateway_used' => $gateway_usado,
        'valor' => 'R$ ' . number_format($valor, 2, ',', '.'),
        'debug' => $debug_info
    ]);
} else {
    $stmt_update->close();
    sendResponse(false, "Erro ao atualizar status no banco de dados", null, 500);
}