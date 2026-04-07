<?php
session_start();
include_once "../config.php";
include_once('../'.DASH.'/services/database.php');
include_once('../'.DASH.'/services/funcao.php');
include_once('../'.DASH.'/services/crud.php');
include_once('../'.DASH.'/services/webhook.php');
global $mysqli;

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit;
}

$idTransaction = PHP_SEGURO($data['idTransaction'] ?? '');
$typeTransaction = strtolower(PHP_SEGURO($data['typeTransaction'] ?? ''));
$statusTransaction = strtolower(PHP_SEGURO($data['statusTransaction'] ?? ''));

// Log debug webhook
$logFileDebug = dirname(__DIR__) . '/errorlog.log';
$debugMsg = "[WEBHOOK VERSELL] Recebido: ID=$idTransaction, Type=$typeTransaction, Status=$statusTransaction" . PHP_EOL;
file_put_contents($logFileDebug, $debugMsg, FILE_APPEND);

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

function registrarBonusUsado($userId, $valorPago, $bonusRecebido) {
    global $mysqli;
    if ($bonusRecebido <= 0) {
        return false;
    }
    $sql = "INSERT INTO cupom_usados (id_user, valor, bonus, data_registro) VALUES (?, ?, ?, NOW())";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("idi", $userId, $valorPago, $bonusRecebido);
    $resultado = $stmt->execute();
    $stmt->close();
    return $resultado;
}

function busca_valor_ipn($transacao_id){
    global $mysqli;
    $qry = "SELECT usuario, valor FROM transacoes WHERE transacao_id = ?";
    $stmt = $mysqli->prepare($qry);
    if (!$stmt) { return false; }
    $stmt->bind_param("s", $transacao_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $data = $res->fetch_assoc();
        $stmt->close();
        $userId = $data['usuario'];
        $valorPago = $data['valor'];
        $bonusRecebido = verificarBonus($userId, $valorPago, $transacao_id);
        $valorTotal = $valorPago + $bonusRecebido;
        $creditado = adicionarSaldoUsuario($userId, $valorTotal);
        if ($creditado) {
            // REGISTRAR AUDIT FLOW (ROLLOVER)
            criarAuditFlowDeposito($userId, $valorPago);

            if ($bonusRecebido > 0) {
                registrarBonusUsado($userId, $valorPago, $bonusRecebido);
            }
            
            // Log debug antes de comissoes
            $logFileDebug = dirname(__DIR__) . '/errorlog.log';
            file_put_contents($logFileDebug, "[WEBHOOK VERSELL] Chamando processarTodasComissoes para User $userId, Valor $valorPago" . PHP_EOL, FILE_APPEND);
            
            processarTodasComissoes($userId, $valorPago);
            $qry_user = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt_user = $mysqli->prepare($qry_user);
            $stmt_user->bind_param("i", $userId);
            $stmt_user->execute();
            $res_user = $stmt_user->get_result();
            $user_data = $res_user->fetch_assoc();
            $stmt_user->close();
            WebhookPixPagos($user_data['nome'] ?? 'Usuário', $_SERVER['HTTP_HOST'], $valorPago);
        }
        return $creditado;
    }
    $stmt->close();
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
            if (strtolower($row['status']) == 'pago') { $stmt_check->close(); return 2; }
        }
        $stmt_check->close();
    }
    $sql = $mysqli->prepare("UPDATE transacoes SET status='pago' WHERE transacao_id=?");
    if (!$sql) { return 0; }
    $sql->bind_param("s", $transacao_id);
    if ($sql->execute()) {
        $buscar = busca_valor_ipn($transacao_id);
        if ($buscar) { return 1; } else { return 0; }
    } else {
        return 0;
    }
}

if (!empty($idTransaction) && $typeTransaction === 'pix' && $statusTransaction === 'paid_out') {
    $att_transacao = att_paymentpix($idTransaction);
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
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Webhook recebido']);

// ==================== LÓGICA DE AFILIAÇÃO (INTEGRADA) ====================

function logAfiliacao($message) {
    $logFile = dirname(__DIR__) . '/errorlog.log';
    $timestamp = date('d-M-Y H:i:s T');
    $formattedMessage = "[$timestamp] [AFILIACAO INTEGRADA] $message" . PHP_EOL;
    file_put_contents($logFile, $formattedMessage, FILE_APPEND);
}

function getAfiliadosConfig() {
    global $mysqli;
    
    if (!$mysqli) {
        logAfiliacao("ERRO CRÍTICO: Conexão MySQL perdida em getAfiliadosConfig.");
        return null;
    }

    $qry = "SELECT * FROM afiliados_config WHERE id = 1";
    $res = mysqli_query($mysqli, $qry);
    
    if (!$res) {
        logAfiliacao("ERRO SQL em getAfiliadosConfig: " . mysqli_error($mysqli));
        return null;
    }

    $config = mysqli_fetch_assoc($res);
    
    if (!$config) {
        logAfiliacao("ERRO: Configuração de afiliados não encontrada (Tabela vazia ou ID 1 inexistente).");
    } else {
        logAfiliacao("Configuração de afiliados carregada com sucesso.");
    }
    
    return $config;
}

function getHierarquiaAfiliados($user_id) {
    global $mysqli;
    
    logAfiliacao("Buscando hierarquia para usuário ID: $user_id");
    
    $hierarquia = [
        'nivel1' => null,
        'nivel2' => null,
        'nivel3' => null
    ];

    if (!$mysqli) {
        logAfiliacao("ERRO CRÍTICO: Conexão MySQL perdida em getHierarquiaAfiliados.");
        return $hierarquia;
    }

    $qry = "SELECT invitation_code FROM usuarios WHERE id = ?";
    $stmt = $mysqli->prepare($qry);
    if (!$stmt) {
        logAfiliacao("ERRO SQL Prepare (User): " . $mysqli->error);
        return $hierarquia;
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user || !$user['invitation_code']) {
        logAfiliacao("Usuário $user_id não tem invitation_code ou não foi encontrado.");
        return $hierarquia;
    }
    
    logAfiliacao("Usuário $user_id convidado por código: " . $user['invitation_code']);

    // Nivel 1
    $qry = "SELECT id, mobile, invite_code, invitation_code FROM usuarios WHERE invite_code = ?";
    $stmt = $mysqli->prepare($qry);
    $stmt->bind_param("s", $user['invitation_code']);
    $stmt->execute();
    $result = $stmt->get_result();
    $nivel1 = $result->fetch_assoc();
    $stmt->close();
    
    if ($nivel1) {
        $hierarquia['nivel1'] = $nivel1;
        logAfiliacao("Nível 1 encontrado: ID " . $nivel1['id']);

        if ($nivel1['invitation_code']) {
            // Nivel 2
            $qry = "SELECT id, mobile, invite_code, invitation_code FROM usuarios WHERE invite_code = ?";
            $stmt = $mysqli->prepare($qry);
            $stmt->bind_param("s", $nivel1['invitation_code']);
            $stmt->execute();
            $result = $stmt->get_result();
            $nivel2 = $result->fetch_assoc();
            $stmt->close();
            
            if ($nivel2) {
                $hierarquia['nivel2'] = $nivel2;
                logAfiliacao("Nível 2 encontrado: ID " . $nivel2['id']);
                
                if ($nivel2['invitation_code']) {
                    // Nivel 3
                    $qry = "SELECT id, mobile, invite_code, invitation_code FROM usuarios WHERE invite_code = ?";
                    $stmt = $mysqli->prepare($qry);
                    $stmt->bind_param("s", $nivel2['invitation_code']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $nivel3 = $result->fetch_assoc();
                    $stmt->close();
                    
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
        $stmt->close();
        return false;
    }
    $stmt->close();

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
        $stmt->close();
        return false;
    }
    $stmt->close();
    
    logAfiliacao("SUCESSO: Comissão creditada para afiliado $user_id");
    return true;
}

function processarCpa($user_id, $valor_deposito) {
    global $mysqli;

    logAfiliacao("Iniciando processamento CPA: Usuario=$user_id, Deposito=$valor_deposito");

    $config = getAfiliadosConfig();
    if (!$config) {
        logAfiliacao("ABORTANDO: Configuração inválida ou erro de DB.");
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

    // Função auxiliar definida aqui para ter acesso ao $mysqli via 'global' ou 'use'
    // Mas vamos simplificar e fazer query direta para evitar erro de closure
    
    $getPorcentagem = function($afiliado_id, $nivel, $global_valor) use ($mysqli) {
        $coluna = "cpaLvl{$nivel}";
        $qry = "SELECT {$coluna} FROM usuarios WHERE id = ?";
        $stmt = $mysqli->prepare($qry);
        if (!$stmt) {
            logAfiliacao("ERRO SQL Prepare (GetPorcentagem): " . $mysqli->error);
            return floatval($global_valor);
        }
        $stmt->bind_param("i", $afiliado_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        
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

    // Check if logs directory is writable
    $logFile = dirname(__DIR__) . '/errorlog.log';
    if (!file_exists($logFile)) {
        file_put_contents($logFile, "Inicializando log...\n");
    }
    
    logAfiliacao("DEBUG: Entrou em processarTodasComissoes(User=$user_id, Valor=$valor_deposito)");
    
    try {
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
        logAfiliacao("DEBUG: Saiu de processarCpa. Resultado: " . ($cpa_processado ? 'TRUE' : 'FALSE'));
        return $cpa_processado ? true : false;
    } catch (Throwable $t) {
        logAfiliacao("ERRO FATAL em processarTodasComissoes: " . $t->getMessage() . " em " . $t->getFile() . ":" . $t->getLine());
        return false;
    } catch (Exception $e) {
        logAfiliacao("ERRO EXCEPTION em processarTodasComissoes: " . $e->getMessage());
        return false;
    }
}
?>
