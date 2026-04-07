<?php
session_start();
include_once "../config.php";
include_once('../'.DASH.'/services/database.php');
include_once('../'.DASH.'/services/funcao.php');
include_once('../'.DASH.'/services/crud.php');
include_once('../'.DASH.'/services/webhook.php');
global $mysqli;

function inpagamentosLog($msg) {
    $logfile = dirname(__DIR__) . '/errorlog.log';
    $date = date('d-M-Y H:i:s T');
    file_put_contents($logfile, "[$date] [INPAGAMENTOS WEBHOOK] $msg" . PHP_EOL, FILE_APPEND);
}

$json = file_get_contents("php://input");
$data = json_decode($json, true);

// Log para debug
inpagamentosLog("Payload recebido: " . $json);

if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit;
}

// Verifica se é um evento de transação
if (!isset($data['type']) || $data['type'] !== 'transaction' || !isset($data['data'])) {
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
    return $cpa_processado ? true : false;
}

http_response_code(200); // Retorna 200 para não travar o webhook, mas ignora
    exit;
}

$transactionData = $data['data'];
$idTransaction = PHP_SEGURO($transactionData['id']); // ID numérico da Inpagamentos
$typeTransaction = strtolower(PHP_SEGURO($transactionData['paymentMethod']));
$statusTransaction = strtolower(PHP_SEGURO($transactionData['status']));

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
    return $stmt->execute();
}

function busca_valor_ipn($transacao_id){
    global $mysqli;
    
    $qry = "SELECT usuario, valor FROM transacoes WHERE transacao_id='" . $transacao_id . "'";
    $res = mysqli_query($mysqli, $qry);
    
    if (mysqli_num_rows($res) > 0) {
        $data = mysqli_fetch_assoc($res);
        $userId = $data['usuario'];
        $valorPago = $data['valor'];
        
        // VERIFICAR E APLICAR BÔNUS
        $bonusRecebido = verificarBonus($userId, $valorPago, $transacao_id);
        $valorTotal = $valorPago + $bonusRecebido;
        
        // Creditar o valor total (depósito + bônus)
        $retorna_insert_saldo = adicionarSaldoUsuario($userId, $valorTotal);
        
        // Se creditou com sucesso
        if ($retorna_insert_saldo) {
            // REGISTRAR AUDIT FLOW (ROLLOVER)
            criarAuditFlowDeposito($userId, $valorPago);
            
            // Registrar o uso do bônus (se houver)
            if ($bonusRecebido > 0) {
                registrarBonusUsado($userId, $valorPago, $bonusRecebido);
                
                // Log para debug
                inpagamentosLog("BÔNUS APLICADO - User: {$userId} | Depósito: R$ {$valorPago} | Bônus: R$ {$bonusRecebido} | Total: R$ {$valorTotal}");
            }
            
            inpagamentosLog("Chamando processarTodasComissoes para User $userId, Valor $valorPago");
            
            // Processar comissões de afiliação (usando o valor PAGO, não o total com bônus)
            processarTodasComissoes($userId, $valorPago);
            
            // 🔔 WEBHOOK: Notificar PIX pago
            $qry_user = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt_user = $mysqli->prepare($qry_user);
            $stmt_user->bind_param("i", $userId);
            $stmt_user->execute();
            $res_user = $stmt_user->get_result();
            $user_data = $res_user->fetch_assoc();
            $stmt_user->close();
            
            WebhookPixPagos($user_data['nome'] ?? 'Usuário', $_SERVER['HTTP_HOST'], $valorPago);
        }
        
        return $retorna_insert_saldo;
    }
    
    return false;
}

function att_paymentpix($transacao_id){
    global $mysqli;
    $stmt_check = $mysqli->prepare("SELECT status FROM transacoes WHERE transacao_id = ?");
    if ($stmt_check) {
        $stmt_check->bind_param("s", $transacao_id);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        if ($res_check && $row = $res_check->fetch_assoc()) {
            if ($row['status'] == 'pago') { $stmt_check->close(); return 2; }
        }
        $stmt_check->close();
    }
    
    $sql = $mysqli->prepare("UPDATE transacoes SET status='pago' WHERE transacao_id=?");
    $sql->bind_param("s", $transacao_id);
    
    if ($sql->execute()) {
        $buscar = busca_valor_ipn($transacao_id);
        if ($buscar) {
            $rf = 1;
        } else {
            $rf = 0;
        }
    } else {
        $rf = 0;
    }
    
    return $rf;
}

// Processar pagamentos PIX confirmados
// Status Inpagamentos: paid (Pagamento confirmado)
if (isset($idTransaction) && $typeTransaction === "pix" && $statusTransaction === "paid") {
    inpagamentosLog("Processando pagamento ID: $idTransaction");
    $att_transacao = att_paymentpix($idTransaction);
    
    if ($att_transacao == 1) {
        inpagamentosLog("Pagamento processado com sucesso ID: $idTransaction");
    } elseif ($att_transacao == 2) {
        inpagamentosLog("Pagamento já estava processado ID: $idTransaction");
    } else {
        inpagamentosLog("Falha ao processar pagamento ID: $idTransaction");
    }

    // Mesmo se já pago, tenta notificar webhook se necessário (mantendo lógica do bspay)
    if ($att_transacao == 2) {
        $qry = "SELECT t.usuario, t.valor, u.nome FROM transacoes t JOIN usuarios u ON u.id = t.usuario WHERE t.transacao_id = ? LIMIT 1";
        $stmt = $mysqli->prepare($qry);
        if ($stmt) {
            $stmt->bind_param("s", $idTransaction);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                WebhookPixPagos($row['nome'] ?? 'Usuário', $_SERVER['HTTP_HOST'], $row['valor'] ?? 0);
            }
            $stmt->close();
        }
    }
} else {
    inpagamentosLog("Ignorado - Status: $statusTransaction, Type: $typeTransaction");
}

http_response_code(200);
?>