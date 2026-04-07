<?php
/**
 * CALLBACK NEXTPAY
 * Webhook para processar notificações de Cash-In (depósitos) e Cash-Out (saques) da NextPay
 * 
 * ATUALIZAÇÃO: Adicionado suporte para webhooks de cashout
 */
 
//ini_set('display_errors', 1);
//error_reporting(E_ALL);
//ini_set('log_errors', 1);
//ini_set('error_log', __DIR__ . '/error.log');

session_start();
include_once "../config.php";
include_once('../' . DASH . '/services/database.php');
include_once('../' . DASH . '/services/funcao.php');
include_once('../' . DASH . '/services/crud.php');
include_once('../' . DASH . '/services/webhook.php');

global $mysqli;

// Capturar payload do webhook
$raw = file_get_contents('php://input');

// Decodificar JSON
$data = json_decode($raw, true);

if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit;
}

log_nextpay("Webhook recebido", $data);

// Função para enviar para webhook de desenvolvimento (debug)
function url_send()
{
    global $data;
    $dev_hook = '';
    
    $ch = curl_init($dev_hook);
    $corpo = json_encode($data);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resultado = curl_exec($ch);
    curl_close($ch);
    
    return $resultado;
}

// Descomentar para debug
// url_send();

function log_nextpay($msg, $context = []) {
    $logFile = dirname(__DIR__) . '/errorlog.log';
    $line = '[NEXTPAY ' . date('Y-m-d H:i:s') . '] ' . $msg;
    if (!empty($context)) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $line .= PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// ==================== FUNÇÕES DE BÔNUS ====================

/**
 * Verifica se existe bônus ativo para o valor e se o usuário ainda não usou
 * Retorna o valor do bônus ou 0
 */
function verificarBonus($userId, $valorPago, $transacao_id = null) {
    global $mysqli;
    
    $sqlCount = "SELECT COUNT(*) as total FROM transacoes WHERE usuario = ? AND tipo = 'deposito' AND status = 'pago'";
    $stmtCount = $mysqli->prepare($sqlCount);
    $stmtCount->bind_param("i", $userId);
    $stmtCount->execute();
    $resCount = $stmtCount->get_result();
    $rowCount = $resCount->fetch_assoc();
    $totalPaid = $rowCount['total'];
    $stmtCount->close();
    
    if ($totalPaid > 1) {
        return 0;
    }

    $tagValue = 0;
    $payTypeId = 0;

    if ($transacao_id) {
        $sqlTrans = "SELECT pay_type_sub_list_id, join_bonus FROM transacoes WHERE transacao_id = ?";
        $stmtTrans = $mysqli->prepare($sqlTrans);
        $stmtTrans->bind_param("s", $transacao_id);
        $stmtTrans->execute();
        $resTrans = $stmtTrans->get_result();
        if ($rowTrans = $resTrans->fetch_assoc()) {
            $payTypeId = $rowTrans['pay_type_sub_list_id'];
            if (isset($rowTrans['join_bonus']) && $rowTrans['join_bonus'] != 1) {
                return 0;
            }
        }
        $stmtTrans->close();
    }

    if ($payTypeId) {
        $sqlPay = "SELECT tag_value, bonus_active FROM pay_type_sub_list WHERE id = ?";
        $stmtPay = $mysqli->prepare($sqlPay);
        $stmtPay->bind_param("i", $payTypeId);
        $stmtPay->execute();
        $resPay = $stmtPay->get_result();
        if ($rowPay = $resPay->fetch_assoc()) {
            if ($rowPay['bonus_active'] == 1) {
                        $percentage = floatval($rowPay['tag_value']);
                        $tagValue = ($valorPago * $percentage) / 100;
                    }
        }
        $stmtPay->close();
    } else {
        // Fallback: Find active bonus rule matching the amount
        $sqlPay = "SELECT tag_value FROM pay_type_sub_list WHERE status = 1 AND bonus_active = 1 AND ? >= min_amount AND ? <= max_amount ORDER BY id DESC LIMIT 1";
        $stmtPay = $mysqli->prepare($sqlPay);
        $stmtPay->bind_param("dd", $valorPago, $valorPago);
        $stmtPay->execute();
        $resPay = $stmtPay->get_result();
        if ($rowPay = $resPay->fetch_assoc()) {
            $percentage = floatval($rowPay['tag_value']);
            $tagValue = ($valorPago * $percentage) / 100;
        }
        $stmtPay->close();
    }
    
    return $tagValue;
}

/**
 * Registra o uso do bônus na tabela cupom_usados
 */
function registrarBonusUsado($userId, $valorPago, $bonusRecebido) {
    global $mysqli;
    
    if ($bonusRecebido <= 0) {
        return false;
    }
    
    $sql = "INSERT INTO cupom_usados (id_user, valor, bonus, data_registro) 
            VALUES (?, ?, ?, NOW())";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("idi", $userId, $valorPago, $bonusRecebido);
    $resultado = $stmt->execute();
    $stmt->close();
    
    return $resultado;
}

// ==================== FUNÇÕES CASH-IN (DEPÓSITOS) ====================

/**
 * Buscar dados da transação de depósito e creditar saldo
 */
function buscarValorIpnCashin($transacao_id)
{
    global $mysqli;
    
    $qry = "SELECT usuario, valor FROM transacoes WHERE transacao_id = ? AND tipo = 'deposito'";
    $stmt = $mysqli->prepare($qry);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $transacao_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows > 0) {
        $data = $res->fetch_assoc();
        $stmt->close();
        
        $usuario_id = $data['usuario'];
        $valorPago = $data['valor'];
    
        // Verificar saldo antes
        $qry_saldo = "SELECT saldo FROM usuarios WHERE id = ?";
        $stmt_saldo = $mysqli->prepare($qry_saldo);
        $stmt_saldo->bind_param("i", $usuario_id);
        $stmt_saldo->execute();
        $res_saldo = $stmt_saldo->get_result();
        $usuario_data = $res_saldo->fetch_assoc();
        $saldo_anterior = $usuario_data['saldo'] ?? 0;
        $stmt_saldo->close();
        
        // ========== VERIFICAR E APLICAR BÔNUS ==========
        $bonusRecebido = verificarBonus($usuario_id, $valorPago, $transacao_id);
        $valorTotal = $valorPago + $bonusRecebido;
        
        if ($bonusRecebido > 0) {
        } else {
        }
        
        // Creditar o valor total (depósito + bônus)
        $retorna_insert_saldo = adicionarSaldoUsuario($usuario_id, $valorTotal);
        
        if ($retorna_insert_saldo) {
            // REGISTRAR AUDIT FLOW (ROLLOVER)
            criarAuditFlowDeposito($usuario_id, $valorPago);
            
            // Verificar saldo depois
            $qry_saldo2 = "SELECT saldo FROM usuarios WHERE id = ?";
            $stmt_saldo2 = $mysqli->prepare($qry_saldo2);
            $stmt_saldo2->bind_param("i", $usuario_id);
            $stmt_saldo2->execute();
            $res_saldo2 = $stmt_saldo2->get_result();
            $usuario_data2 = $res_saldo2->fetch_assoc();
            $saldo_novo = $usuario_data2['saldo'] ?? 0;
            $stmt_saldo2->close();
            
            // Registrar o uso do bônus (se houver)
            if ($bonusRecebido > 0) {
                registrarBonusUsado($usuario_id, $valorPago, $bonusRecebido);
            }
            
            log_nextpay("Chamando processarTodasComissoes para User $usuario_id, Valor $valorPago");
            
            // Processar comissões de afiliação (usando valor PAGO, não o total com bônus)
            $comissoes_processadas = processarTodasComissoes($usuario_id, $valorPago);
            
            if ($comissoes_processadas) {
            } else {
            }
            
            // 🔔 WEBHOOK: Notificar PIX pago
            $qry_user = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt_user = $mysqli->prepare($qry_user);
            $stmt_user->bind_param("i", $usuario_id);
            $stmt_user->execute();
            $res_user = $stmt_user->get_result();
            $user_data = $res_user->fetch_assoc();
            $stmt_user->close();
            
            WebhookPixPagos($user_data['nome'] ?? 'Usuário', $_SERVER['HTTP_HOST'], $valorPago);
            
            return true;
        } else {
            return false;
        }
    }
    
    $stmt->close();
    return false;
}

/**
 * Atualizar status da transação de depósito
 */
function attPaymentPix($transacao_id)
{
    global $mysqli;
    
    // Verificar se já foi processado
    $qry_check = "SELECT status FROM transacoes WHERE transacao_id = ? AND tipo = 'deposito'";
    $stmt_check = $mysqli->prepare($qry_check);
    
    if ($stmt_check) {
        $stmt_check->bind_param("s", $transacao_id);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        
        if ($res_check->num_rows > 0) {
            $trans = $res_check->fetch_assoc();
            
            if ($trans['status'] == 'pago') {
                $stmt_check->close();
                return 2; // Código especial: já processado
            }
        } else {
            $stmt_check->close();
            return 0;
        }
        $stmt_check->close();
    }
    
    $sql = $mysqli->prepare("UPDATE transacoes SET status = 'pago' WHERE transacao_id = ? AND tipo = 'deposito'");
    
    if (!$sql) {
        return 0;
    }
    
    $sql->bind_param("s", $transacao_id);
    
    if ($sql->execute()) {
        $linhas_afetadas = $sql->affected_rows;
        $sql->close();
        
        if ($linhas_afetadas > 0) {
            $buscar = buscarValorIpnCashin($transacao_id);
            
            if ($buscar) {
                return 1;
            } else {
                return 0;
            }
        } else {
            return 0;
        }
    } else {
        $sql->close();
        return 0;
    }
}

/**
 * Processar webhook de Cash-In
 */
function processarWebhookCashin($webhook_data)
{
    $type = strtolower(PHP_SEGURO($webhook_data['type'] ?? ''));
    $status = strtolower(PHP_SEGURO($webhook_data['status'] ?? ''));
    $amount = PHP_SEGURO($webhook_data['amount'] ?? 0);
    $fee = PHP_SEGURO($webhook_data['fee'] ?? 0);
    $request_number = PHP_SEGURO($webhook_data['request_number'] ?? '');
    $transaction_id = PHP_SEGURO($webhook_data['transaction_id'] ?? '');
    $out_trade_no = PHP_SEGURO($webhook_data['outTradeNo'] ?? ($webhook_data['out_trade_no'] ?? ''));
    $order_no = PHP_SEGURO($webhook_data['orderNo'] ?? ($webhook_data['order_no'] ?? ''));
    $e2e = PHP_SEGURO($webhook_data['e2e'] ?? '');
    $provider = PHP_SEGURO($webhook_data['provider'] ?? '');
    $updated_at = PHP_SEGURO($webhook_data['updated_at'] ?? '');

    // Aceitar múltiplos status de confirmação
    $valid_statuses = ['confirmed', 'paid', 'success', 'completed', 'approved'];
    // Normalizar tipo (aceitar variações comuns)
    $type_norm = str_replace(['_', '-'], '', $type);
    $is_cashin = in_array($type_norm, ['cashin', 'deposit', 'pix', 'payment']);

    $id_busca = '';
    foreach ([$request_number, $transaction_id, $out_trade_no, $order_no] as $cand) {
        if (!empty($cand)) { $id_busca = $cand; break; }
    }

    log_nextpay('[NEXTPAY CASHIN] Payload recebido', [
        'type' => $type,
        'status' => $status,
        'id_candidates' => [
            'request_number' => $request_number,
            'transaction_id' => $transaction_id,
            'out_trade_no' => $out_trade_no,
            'order_no' => $order_no
        ]
    ]);

    if ($is_cashin && !empty($id_busca) && in_array($status, $valid_statuses)) {
        $att_transacao = attPaymentPix($id_busca);

        if ($att_transacao == 1) {
            log_nextpay('[NEXTPAY CASHIN] Pagamento processado', ['id' => $id_busca]);
            return ['success' => true, 'message' => 'Pagamento processado'];
        } elseif ($att_transacao == 2) {
            // Já processado: garantir notificação sem recreditar
            global $mysqli;
            $qry = "SELECT t.usuario, t.valor, u.nome FROM transacoes t JOIN usuarios u ON u.id = t.usuario WHERE t.transacao_id = ? LIMIT 1";
            $stmt = $mysqli->prepare($qry);
            if ($stmt) {
                $stmt->bind_param("s", $id_busca);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $row = $res->fetch_assoc()) {
                    WebhookPixPagos($row['nome'] ?? 'Usuário', $_SERVER['HTTP_HOST'], $row['valor'] ?? 0);
                    log_nextpay('[NEXTPAY CASHIN] Reentrega: webhook Pix pago reenviado', ['id' => $id_busca]);
                }
                $stmt->close();
            }
            return ['success' => true, 'message' => 'Pagamento já processado'];
        } else {
            log_nextpay('[NEXTPAY CASHIN] Erro ao processar pagamento', ['id' => $id_busca]);
            return ['success' => false, 'message' => 'Erro ao processar'];
        }
    } else {
        log_nextpay('[NEXTPAY CASHIN] Ignorado', [
            'is_cashin' => $is_cashin,
            'id_busca_vazio' => empty($id_busca),
            'status_aceito' => in_array($status, $valid_statuses)
        ]);
        return ['success' => true, 'message' => 'Status registrado'];
    }
}

// ==================== FUNÇÕES CASH-OUT (SAQUES) ====================

/**
 * Atualizar status da solicitação de saque
 * 
 * @param string $transaction_id - ID da transação (pode conter o e2e)
 * @param string $status - Status do saque: 'completed', 'pending', 'failed', etc.
 * @param string $e2e - End-to-End ID do saque
 * @return int - 0: erro, 1: sucesso, 2: já processado, 3: saque não encontrado
 */
function attSaqueStatus($transaction_id, $status, $e2e = '')
{
    global $mysqli;
    
    // Tentar localizar o saque pelo transaction_id ou e2e
    $qry_find = "SELECT transacao_id, status, id_user, valor FROM solicitacao_saques 
                 WHERE transacao_id = ? OR transacao_id = ? 
                 ORDER BY data_registro DESC LIMIT 1";
    $stmt_find = $mysqli->prepare($qry_find);
    
    if (!$stmt_find) {
        error_log("[NEXTPAY CASHOUT] Erro ao preparar query de busca");
        return 0;
    }
    
    $stmt_find->bind_param("ss", $transaction_id, $e2e);
    $stmt_find->execute();
    $res_find = $stmt_find->get_result();
    
    if ($res_find->num_rows === 0) {
        $stmt_find->close();
        error_log("[NEXTPAY CASHOUT] Saque não encontrado: $transaction_id / $e2e");
        return 3; // Saque não encontrado
    }
    
    $saque = $res_find->fetch_assoc();
    $transacao_id_real = $saque['transacao_id'];
    $status_atual = $saque['status'];
    $usuario_id = $saque['id_user'];
    $valor_saque = $saque['valor'];
    $stmt_find->close();
    
    // Se status for "completed" e já estiver como status = 1, não processar novamente
    if ($status === 'completed' && $status_atual == 1) {
        error_log("[NEXTPAY CASHOUT] Saque já processado: $transacao_id_real");
        return 2; // Já processado
    }
    
    // Mapear status da NextPay para status no banco
    $status_db = 0; // padrão: pendente
    
    switch (strtolower($status)) {
        case 'completed':
        case 'success':
        case 'approved':
            $status_db = 1; // Aprovado
            break;
            
        case 'failed':
        case 'rejected':
        case 'cancelled':
        case 'error':
            $status_db = 2; // Rejeitado/Cancelado
            break;
            
        case 'pending':
        case 'processing':
        default:
            $status_db = 0; // Pendente
            break;
    }
    
    // Atualizar status do saque
    $sql_update = "UPDATE solicitacao_saques 
                   SET status = ?, data_att = NOW() 
                   WHERE transacao_id = ?";
    $stmt_update = $mysqli->prepare($sql_update);
    
    if (!$stmt_update) {
        error_log("[NEXTPAY CASHOUT] Erro ao preparar query de atualização");
        return 0;
    }
    
    $stmt_update->bind_param("is", $status_db, $transacao_id_real);
    
    if ($stmt_update->execute()) {
        $linhas_afetadas = $stmt_update->affected_rows;
        $stmt_update->close();
        
        if ($linhas_afetadas > 0) {
            error_log("[NEXTPAY CASHOUT] Saque atualizado com sucesso: $transacao_id_real - Status: $status_db");
            
            // 🔔 WEBHOOK: Notificar saque pago se status for 1 (aprovado)
            if ($status_db == 1) {
                $qry_user = "SELECT nome FROM usuarios WHERE id = ?";
                $stmt_user = $mysqli->prepare($qry_user);
                $stmt_user->bind_param("i", $usuario_id);
                $stmt_user->execute();
                $res_user = $stmt_user->get_result();
                $user_data = $res_user->fetch_assoc();
                $stmt_user->close();
                
                WebhookSaquesPagos($user_data['nome'] ?? 'Usuário', $_SERVER['HTTP_HOST'], $valor_saque);
            }
            
            return 1;
        } else {
            error_log("[NEXTPAY CASHOUT] Nenhuma linha afetada: $transacao_id_real");
            return 0;
        }
    } else {
        error_log("[NEXTPAY CASHOUT] Erro ao executar update");
        $stmt_update->close();
        return 0;
    }
}

/**
 * Processar webhook de Cash-Out (Saques)
 * 
 * Payload esperado:
 * {
 *   "type": "cashout",
 *   "status": "completed",
 *   "amount": 250.00,
 *   "fee": 2.50,
 *   "transaction_id": "...",
 *   "e2e": "WD_...",
 *   "updated_at": "2025-11-24 12:00:00"
 * }
 */
function processarWebhookCashout($webhook_data)
{
    $type = PHP_SEGURO($webhook_data['type'] ?? '');
    $status = strtolower(PHP_SEGURO($webhook_data['status'] ?? ''));
    $amount = PHP_SEGURO($webhook_data['amount'] ?? 0);
    $fee = PHP_SEGURO($webhook_data['fee'] ?? 0);
    $transaction_id = PHP_SEGURO($webhook_data['transaction_id'] ?? '');
    $e2e = PHP_SEGURO($webhook_data['e2e'] ?? '');
    $updated_at = PHP_SEGURO($webhook_data['updated_at'] ?? '');
    
    error_log("[NEXTPAY CASHOUT] Webhook recebido - Type: $type, Status: $status, TxID: $transaction_id, E2E: $e2e");
    
    // Validar que é um cashout
    if ($type !== 'cashout') {
        error_log("[NEXTPAY CASHOUT] Tipo inválido: $type");
        return ['success' => false, 'message' => 'Tipo de webhook inválido'];
    }
    
    // Validar que há um identificador
    if (empty($transaction_id) && empty($e2e)) {
        error_log("[NEXTPAY CASHOUT] Sem identificador válido");
        return ['success' => false, 'message' => 'Transaction ID ou E2E não informado'];
    }
    
    // Usar e2e como fallback se transaction_id estiver vazio
    $id_busca = !empty($transaction_id) ? $transaction_id : $e2e;
    
    // Atualizar status do saque
    $resultado = attSaqueStatus($id_busca, $status, $e2e);
    
    switch ($resultado) {
        case 1:
            return ['success' => true, 'message' => 'Saque atualizado com sucesso'];
        case 2:
            return ['success' => true, 'message' => 'Saque já processado anteriormente'];
        case 3:
            return ['success' => false, 'message' => 'Saque não encontrado'];
        default:
            return ['success' => false, 'message' => 'Erro ao atualizar saque'];
    }
}

// ==================== LÓGICA DE AFILIAÇÃO (INTEGRADA) ====================

function logAfiliacao($message) {
    // Caminho dinâmico para o arquivo de log na raiz (public/errorlog.log)
    $logFile = dirname(__DIR__) . '/errorlog.log';
    $timestamp = date('d-M-Y H:i:s T');
    $formattedMessage = "[$timestamp] [AFILIACAO INTEGRADA] $message" . PHP_EOL;
    
    // Tenta escrever no arquivo
    file_put_contents($logFile, $formattedMessage, FILE_APPEND);
}

/**
 * Busca a configuração de afiliados
 */
function getAfiliadosConfig() {
    global $mysqli;
    $qry = "SELECT * FROM afiliados_config WHERE id = 1";
    $res = mysqli_query($mysqli, $qry);
    $config = mysqli_fetch_assoc($res);
    
    if (!$config) {
        logAfiliacao("ERRO: Configuração de afiliados não encontrada.");
    }
    
    return $config;
}

/**
 * Busca a hierarquia de afiliados de um usuário (até 3 níveis)
 */
function getHierarquiaAfiliados($user_id) {
    global $mysqli;
    
    logAfiliacao("Buscando hierarquia para usuário ID: $user_id");
    
    $hierarquia = [
        'nivel1' => null,
        'nivel2' => null,
        'nivel3' => null
    ];

    $qry = "SELECT invitation_code FROM usuarios WHERE id = ?";
    $stmt = $mysqli->prepare($qry);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user || !$user['invitation_code']) {
        logAfiliacao("Usuário $user_id não tem invitation_code ou não foi encontrado.");
        return $hierarquia;
    }
    
    logAfiliacao("Usuário $user_id convidado por código: " . $user['invitation_code']);

    $qry = "SELECT id, mobile, invite_code, invitation_code FROM usuarios WHERE invite_code = ?";
    $stmt = $mysqli->prepare($qry);
    $stmt->bind_param("s", $user['invitation_code']);
    $stmt->execute();
    $result = $stmt->get_result();
    $nivel1 = $result->fetch_assoc();
    
    if ($nivel1) {
        $hierarquia['nivel1'] = $nivel1;
        logAfiliacao("Nível 1 encontrado: ID " . $nivel1['id']);

        if ($nivel1['invitation_code']) {
            $qry = "SELECT id, mobile, invite_code, invitation_code FROM usuarios WHERE invite_code = ?";
            $stmt = $mysqli->prepare($qry);
            $stmt->bind_param("s", $nivel1['invitation_code']);
            $stmt->execute();
            $result = $stmt->get_result();
            $nivel2 = $result->fetch_assoc();
            
            if ($nivel2) {
                $hierarquia['nivel2'] = $nivel2;
                logAfiliacao("Nível 2 encontrado: ID " . $nivel2['id']);
                
                if ($nivel2['invitation_code']) {
                    $qry = "SELECT id, mobile, invite_code, invitation_code FROM usuarios WHERE invite_code = ?";
                    $stmt = $mysqli->prepare($qry);
                    $stmt->bind_param("s", $nivel2['invitation_code']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $nivel3 = $result->fetch_assoc();
                    
                    if ($nivel3) {
                        $hierarquia['nivel3'] = $nivel3;
                        logAfiliacao("Nível 3 encontrado: ID " . $nivel3['id']);
                    }
                }
            }
        }
    } else {
        logAfiliacao("Nenhum usuário encontrado com o código " . $user['invitation_code']);
    }
    
    return $hierarquia;
}

/**
 * Verifica se deve aplicar a comissão baseado na chance CPA
 */
function aplicarChanceCpa($chanceCpa) {
    if ($chanceCpa >= 100) {
        logAfiliacao("Chance CPA é 100% ou mais. Aprovado.");
        return true;
    }
    
    $random = mt_rand(1, 100);
    $aprovado = $random <= $chanceCpa;
    logAfiliacao("Sorteio Chance CPA: Random=$random, Chance=$chanceCpa. Resultado: " . ($aprovado ? "Aprovado" : "Reprovado"));
    return $aprovado;
}

/**
 * Credita comissão no saldo de afiliados
 */
function creditarComissaoAfiliado($user_id, $valor, $nivel, $depositante_id, $valor_deposito, $porcentagem) {
    global $mysqli;
    
    logAfiliacao("Creditando comissão: Afiliado=$user_id, Valor=$valor, Nivel=$nivel, Deposito=$valor_deposito, %=$porcentagem");
    
    $qry = "UPDATE usuarios SET saldo_afiliados = saldo_afiliados + ? WHERE id = ?";
    $stmt = $mysqli->prepare($qry);
    
    if (!$stmt) {
        logAfiliacao("ERRO Prepare Update Saldo: " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("di", $valor, $user_id);
    
    if (!$stmt->execute()) {
        logAfiliacao("ERRO Execute Update Saldo: " . $stmt->error);
        return false;
    }

    $data_registro = date('Y-m-d H:i:s');
    $tipo = "comissao_cpa_nivel_{$nivel}";
    $valor_centavos = intval($valor * 100);
    
    $qry = "INSERT INTO adicao_saldo (id_user, valor, tipo, data_registro) VALUES (?, ?, ?, ?)";
    $stmt = $mysqli->prepare($qry);
    
    if (!$stmt) {
        logAfiliacao("ERRO Prepare Insert AdicaoSaldo: " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("iiss", $user_id, $valor_centavos, $tipo, $data_registro);
    
    if (!$stmt->execute()) {
        logAfiliacao("ERRO Execute Insert AdicaoSaldo: " . $stmt->error);
        return false;
    }
    
    logAfiliacao("SUCESSO: Comissão creditada para afiliado $user_id");
    return true;
}

/**
 * Processa as comissões CPA para um depósito
 */
function processarCpa($user_id, $valor_deposito) {
    global $mysqli;

    logAfiliacao("Iniciando processamento CPA: Usuario=$user_id, Deposito=$valor_deposito");

    $config = getAfiliadosConfig();
    if (!$config) {
        logAfiliacao("ABORTANDO: Configuração inválida.");
        return false;
    }
    
    if ($valor_deposito < $config['minDepForCpa']) {
        logAfiliacao("ABORTANDO: Valor do depósito ($valor_deposito) menor que mínimo para CPA ({$config['minDepForCpa']})");
        return false;
    }
    
    if (!aplicarChanceCpa($config['chanceCpa'])) {
        logAfiliacao("ABORTANDO: Reprovado no teste de chance CPA.");
        return false;
    }

    $hierarquia = getHierarquiaAfiliados($user_id);
    $comissoes_processadas = 0;

    $getPorcentagem = function($afiliado_id, $nivel, $global_valor) use ($mysqli) {
        $coluna = "cpaLvl{$nivel}";
        $qry = "SELECT {$coluna} FROM usuarios WHERE id = ?";
        $stmt = $mysqli->prepare($qry);
        $stmt->bind_param("i", $afiliado_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        
        $valor_final = floatval($global_valor);
        if ($row && isset($row[$coluna]) && floatval($row[$coluna]) > 0) {
            $valor_final = floatval($row[$coluna]);
            logAfiliacao("Usando CPA personalizado para afiliado $afiliado_id (Nivel $nivel): $valor_final%");
        } else {
            logAfiliacao("Usando CPA global para afiliado $afiliado_id (Nivel $nivel): $valor_final%");
        }
        return $valor_final;
    };

    if ($hierarquia['nivel1']) {
        $porcentagem_nivel1 = $getPorcentagem($hierarquia['nivel1']['id'], 1, $config['cpaLvl1']);
        if ($porcentagem_nivel1 > 0) {
            $comissao_nivel1 = ($valor_deposito * $porcentagem_nivel1) / 100;
            logAfiliacao("Nível 1: Calculado $comissao_nivel1 ($porcentagem_nivel1%)");
            if (creditarComissaoAfiliado(
                $hierarquia['nivel1']['id'], 
                $comissao_nivel1, 
                1, 
                $user_id,
                $valor_deposito,
                $porcentagem_nivel1
            )) {
                $comissoes_processadas++;
            }
        } else {
            logAfiliacao("Nível 1: Porcentagem zerada.");
        }

        if ($hierarquia['nivel2']) {
            $porcentagem_nivel2 = $getPorcentagem($hierarquia['nivel2']['id'], 2, $config['cpaLvl2']);
            if ($porcentagem_nivel2 > 0) {
                $comissao_nivel2 = ($valor_deposito * $porcentagem_nivel2) / 100;
                logAfiliacao("Nível 2: Calculado $comissao_nivel2 ($porcentagem_nivel2%)");
                if (creditarComissaoAfiliado(
                    $hierarquia['nivel2']['id'], 
                    $comissao_nivel2, 
                    2, 
                    $user_id,
                    $valor_deposito,
                    $porcentagem_nivel2
                )) {
                    $comissoes_processadas++;
                }
            } else {
                logAfiliacao("Nível 2: Porcentagem zerada.");
            }

            if ($hierarquia['nivel3']) {
                $porcentagem_nivel3 = $getPorcentagem($hierarquia['nivel3']['id'], 3, $config['cpaLvl3']);
                if ($porcentagem_nivel3 > 0) {
                    $comissao_nivel3 = ($valor_deposito * $porcentagem_nivel3) / 100;
                    logAfiliacao("Nível 3: Calculado $comissao_nivel3 ($porcentagem_nivel3%)");
                    if (creditarComissaoAfiliado(
                        $hierarquia['nivel3']['id'], 
                        $comissao_nivel3, 
                        3, 
                        $user_id,
                        $valor_deposito,
                        $porcentagem_nivel3
                    )) {
                        $comissoes_processadas++;
                    }
                } else {
                    logAfiliacao("Nível 3: Porcentagem zerada.");
                }
            }
        }
    } else {
        logAfiliacao("Nenhum afiliado de Nível 1 encontrado.");
    }

    $resultado = $comissoes_processadas > 0;
    logAfiliacao("Fim processamento CPA. Total processado: $comissoes_processadas. Retorno: " . ($resultado ? "TRUE" : "FALSE"));
    return $resultado;
}

/**
 * Função principal para processar comissões CPA de um depósito
 * @param int $user_id ID do usuário que fez o depósito
 * @param float $valor_deposito Valor do depósito
 */
function processarTodasComissoes($user_id, $valor_deposito) {
    global $mysqli;

    logAfiliacao("DEBUG: Entrou em processarTodasComissoes(User=$user_id, Valor=$valor_deposito)");

    // Verificar se é o primeiro depósito PAGO
    $sqlCount = "SELECT COUNT(*) as total FROM transacoes WHERE usuario = ? AND tipo = 'deposito' AND status = 'pago'";
    $stmtCount = $mysqli->prepare($sqlCount);
    $stmtCount->bind_param("i", $user_id);
    $stmtCount->execute();
    $resCount = $stmtCount->get_result();
    $rowCount = $resCount->fetch_assoc();
    $totalPaid = $rowCount['total'];
    $stmtCount->close();
    
    if ($totalPaid > 1) {
        logAfiliacao("ABORTANDO: Já possui depósitos pagos anteriores. Total: $totalPaid");
        return false;
    }

    $cpa_processado = processarCpa($user_id, $valor_deposito);
    
    if ($cpa_processado) {
        return true;
    } else {
        return false;
    }
}

$type = PHP_SEGURO($data['type'] ?? '');
$typeNorm = strtolower($type);
if ($typeNorm === 'cashin' || $typeNorm === 'deposit' || $typeNorm === 'pix' || $typeNorm === 'payment') {
    $resultado = processarWebhookCashin($data);
} elseif ($typeNorm === 'cashout' || $typeNorm === 'withdraw') {
    $resultado = processarWebhookCashout($data);
} else {
    error_log("[NEXTPAY] Tipo de webhook desconhecido: " . json_encode($data));
    $resultado = ['success' => false, 'message' => 'Tipo de webhook não reconhecido'];
}
if ($resultado && $resultado['success']) {
    http_response_code(200);
    echo json_encode($resultado);
} else {
    http_response_code(500);
    echo json_encode($resultado ?: ['success' => false, 'message' => 'Erro interno']);
}

?>
