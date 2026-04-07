<?php
session_start();
include_once "../config.php";
include_once('../'.DASH.'/services/database.php');
include_once('../'.DASH.'/services/funcao.php');
include_once('../'.DASH.'/services/crud.php');
include_once('../'.DASH.'/services/webhook.php');
global $mysqli;

$data = json_decode(file_get_contents("php://input"), true);
$raw_input = file_get_contents("php://input");
log_bspay("Payload RAW recebido: " . $raw_input);

if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    log_bspay("ERRO JSON: " . json_last_error_msg());
    http_response_code(400);
    exit;
}

$idTransaction = PHP_SEGURO($data['requestBody']['transactionId']);
$typeTransaction = strtolower(PHP_SEGURO($data['requestBody']['paymentType']));
$statusTransaction = strtolower(PHP_SEGURO($data['requestBody']['status']));

log_bspay("Recebido: ID=$idTransaction, Type=$typeTransaction, Status=$statusTransaction");

$dev_hook = '';

function log_bspay($msg) {
    $logfile = dirname(__DIR__) . '/errorlog.log';
    $date = date('d-M-Y H:i:s T');
    file_put_contents($logfile, "[$date] [BSPAY WEBHOOK] $msg" . PHP_EOL, FILE_APPEND);
}

function url_send(){
    global $data, $dev_hook;
    $url = $dev_hook;
    $ch = curl_init($url);
    $corpo = json_encode($data);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resultado = curl_exec($ch);
    curl_close($ch);
    return $resultado;
}

function verificarBonus($userId, $valorPago, $transacao_id = null) {
    global $mysqli;
    
    log_bspay("verificarBonus: Iniciando verificação para User $userId, Valor $valorPago, TransID $transacao_id");

    $sqlCount = "SELECT COUNT(*) as total FROM transacoes WHERE usuario = ? AND tipo = 'deposito' AND status = 'pago'";
    $stmtCount = $mysqli->prepare($sqlCount);
    $stmtCount->bind_param("i", $userId);
    $stmtCount->execute();
    $resCount = $stmtCount->get_result();
    $rowCount = $resCount->fetch_assoc();
    $totalPaid = $rowCount['total'];
    $stmtCount->close();
    
    log_bspay("verificarBonus: Total de depósitos pagos encontrados: $totalPaid");

    if ($totalPaid > 1) {
        log_bspay("verificarBonus: Usuário já possui mais de 1 depósito pago. Bônus = 0");
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
            log_bspay("verificarBonus: PayTypeID encontrado na transação: $payTypeId. JoinBonus: " . ($rowTrans['join_bonus'] ?? 'N/A'));
            if (isset($rowTrans['join_bonus']) && $rowTrans['join_bonus'] != 1) {
                log_bspay("verificarBonus: join_bonus != 1. Retornando 0.");
                return 0;
            }
        }
        $stmtTrans->close();
    }

    if ($payTypeId) {
        log_bspay("verificarBonus: Buscando regras para PayTypeID $payTypeId");
        $sqlPay = "SELECT tag_value, bonus_active FROM pay_type_sub_list WHERE id = ?";
        $stmtPay = $mysqli->prepare($sqlPay);
        $stmtPay->bind_param("i", $payTypeId);
        $stmtPay->execute();
        $resPay = $stmtPay->get_result();
        if ($rowPay = $resPay->fetch_assoc()) {
            if ($rowPay['bonus_active'] == 1) {
                $percentage = floatval($rowPay['tag_value']);
                $tagValue = ($valorPago * $percentage) / 100;
                log_bspay("verificarBonus: Regra por ID encontrada. Percent: $percentage%. Valor Bônus: $tagValue");
            } else {
                log_bspay("verificarBonus: bonus_active não é 1.");
            }
        }
        $stmtPay->close();
    } else {
        log_bspay("verificarBonus: Buscando regra genérica por valor.");
        $sqlPay = "SELECT tag_value FROM pay_type_sub_list WHERE status = 1 AND bonus_active = 1 AND ? >= min_amount AND ? <= max_amount ORDER BY id DESC LIMIT 1";
        $stmtPay = $mysqli->prepare($sqlPay);
        $stmtPay->bind_param("dd", $valorPago, $valorPago);
        $stmtPay->execute();
        $resPay = $stmtPay->get_result();
        if ($rowPay = $resPay->fetch_assoc()) {
            $percentage = floatval($rowPay['tag_value']);
            $tagValue = ($valorPago * $percentage) / 100;
            log_bspay("verificarBonus: Regra genérica encontrada. Percent: $percentage%. Valor Bônus: $tagValue");
        } else {
            log_bspay("verificarBonus: Nenhuma regra genérica encontrada.");
        }
        $stmtPay->close();
    }
    
    log_bspay("verificarBonus: Retornando $tagValue");
    return $tagValue;
}

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
        
        log_bspay("Transação encontrada. User: $userId, Valor: $valorPago. Verificando bônus...");
        
        $bonusRecebido = verificarBonus($userId, $valorPago, $transacao_id);
        $valorTotal = $valorPago + $bonusRecebido;
        
        log_bspay("Bônus calculado: $bonusRecebido. Total a creditar: $valorTotal");

        // Usar função local para garantir logs
        log_bspay("Chamando adicionarSaldoUsuarioWebhook($userId, $valorTotal)...");
        $retorna_insert_saldo = adicionarSaldoUsuarioWebhook($userId, $valorTotal);
        
        if ($retorna_insert_saldo) {
            // REGISTRAR AUDIT FLOW (ROLLOVER)
            criarAuditFlowDeposito($userId, $valorPago);
            log_bspay("AuditFlow criado para User $userId com valor $valorPago");

            log_bspay("Saldo creditado com sucesso. Retorno: TRUE");
            if ($bonusRecebido > 0) {
                registrarBonusUsado($userId, $valorPago, $bonusRecebido);
                log_bspay("✓ Bônus registrado em cupom_usados");
            }
            
            log_bspay("Chamando processarTodasComissoes para User $userId, Valor $valorPago");
            
            if (function_exists('processarTodasComissoes')) {
                try {
                    log_bspay("Iniciando execução de processarTodasComissoes...");
                    $resultado_comissao = processarTodasComissoes($userId, $valorPago);
                    log_bspay("Retornou de processarTodasComissoes. Resultado: " . ($resultado_comissao ? 'TRUE' : 'FALSE'));
                } catch (Throwable $t) {
                    log_bspay("ERRO FATAL ao executar processarTodasComissoes: " . $t->getMessage());
                } catch (Exception $e) {
                    log_bspay("ERRO EXCEPTION ao executar processarTodasComissoes: " . $e->getMessage());
                }
            } else {
                log_bspay("ERRO CRÍTICO: Função processarTodasComissoes NÃO ENCONTRADA no escopo atual.");
            }

            $qry_user = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt_user = $mysqli->prepare($qry_user);
            $stmt_user->bind_param("i", $userId);
            $stmt_user->execute();
            $res_user = $stmt_user->get_result();
            $user_data = $res_user->fetch_assoc();
            $stmt_user->close();
            
            WebhookPixPagos($user_data['nome'] ?? 'Usuário', $_SERVER['HTTP_HOST'], $valorPago);
            log_bspay("WebhookPixPagos chamado com sucesso.");
        } else {
            log_bspay("ERRO: adicionarSaldoUsuarioWebhook retornou FALSE. User: $userId, Valor: $valorTotal");
        }
        
        return $retorna_insert_saldo;
    }
    
    log_bspay("Transação ID $transacao_id não encontrada no banco de dados.");
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
            if ($row['status'] == 'pago') { 
                log_bspay("Transação já estava paga (status=pago). Ignorando processamento.");
                $stmt_check->close(); 
                return 2; 
            }
        }
        $stmt_check->close();
    }
    
    $sql = $mysqli->prepare("UPDATE transacoes SET status='pago' WHERE transacao_id=?");
    $sql->bind_param("s", $transacao_id);
    
    if ($sql->execute()) {
        log_bspay("Status atualizado para 'pago'. Chamando busca_valor_ipn...");
        $buscar = busca_valor_ipn($transacao_id);
        if ($buscar) {
            $rf = 1;
        } else {
            log_bspay("busca_valor_ipn retornou false.");
            $rf = 0;
        }
    } else {
        log_bspay("Erro ao atualizar status para 'pago': " . $mysqli->error);
        $rf = 0;
    }
    
    return $rf;
}

if (isset($idTransaction) && $typeTransaction === "pix" && $statusTransaction === "paid") {
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
}

function adicionarSaldoUsuarioWebhook($id_user, $valor) {
    global $mysqli;
    
    log_bspay("adicionarSaldoUsuarioWebhook: Iniciando para User $id_user, Valor $valor");
    
    try {
        if (!$mysqli) {
            log_bspay("ERRO: Conexão MySQL inválida.");
            return false;
        }
        
        // 1. Get Current Balance
        log_bspay("adicionarSaldoUsuarioWebhook: Preparando SELECT saldo...");
        $stmt = $mysqli->prepare("SELECT saldo FROM usuarios WHERE id = ?");
        if (!$stmt) {
            log_bspay("ERRO Prepare Select Saldo: " . $mysqli->error);
            return false;
        }
        $stmt->bind_param("i", $id_user);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            log_bspay("ERRO: Usuário $id_user não encontrado.");
            $stmt->close();
            return false;
        }
        $row = $res->fetch_assoc();
        $saldo_atual = $row['saldo'];
        $stmt->close();
        
        $novo_saldo = $saldo_atual + $valor;
        log_bspay("Saldo Atual: $saldo_atual. Novo Saldo: $novo_saldo. Iniciando UPDATE...");
        
        // 2. Update Balance
        $stmt = $mysqli->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
        if (!$stmt) {
            log_bspay("ERRO Prepare Update Saldo: " . $mysqli->error);
            return false;
        }
        $stmt->bind_param("di", $novo_saldo, $id_user);
        log_bspay("adicionarSaldoUsuarioWebhook: Executando UPDATE...");
        if (!$stmt->execute()) {
            log_bspay("ERRO Execute Update Saldo: " . $stmt->error);
            $stmt->close();
            return false;
        }
        $stmt->close();
        log_bspay("adicionarSaldoUsuarioWebhook: UPDATE concluído. Preparando INSERT log...");
        
        // 3. Log Transaction
        $tipo = "deposito_pix";
        // observacao column does not exist in adicao_saldo table
        // $observacao = "Depósito PIX confirmado (Webhook BSPAY)";
        $data_registro = date('Y-m-d H:i:s');
        
        // Removed observacao from query
        $stmt = $mysqli->prepare("INSERT INTO adicao_saldo (id_user, valor, tipo, data_registro) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("idss", $id_user, $valor, $tipo, $data_registro);
            if (!$stmt->execute()) {
                 log_bspay("ERRO Execute Insert AdicaoSaldo: " . $stmt->error);
            } else {
                 log_bspay("Log de saldo gravado em adicao_saldo.");
            }
            $stmt->close();
        } else {
            log_bspay("AVISO: Falha ao preparar insert em adicao_saldo: " . $mysqli->error);
        }
        
        return true;
    } catch (Throwable $e) {
        log_bspay("ERRO CRÍTICO (Exception) em adicionarSaldoUsuarioWebhook: " . $e->getMessage());
        return false;
    }
}

function logAfiliacao($message) {
    log_bspay("[AFILIACAO] $message");
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
    $valor_db = $valor; 
    
    $qry = "INSERT INTO adicao_saldo (id_user, valor, tipo, data_registro) VALUES (?, ?, ?, ?)";
    $stmt = $mysqli->prepare($qry);
    
    if (!$stmt) {
        logAfiliacao("ERRO Prepare Insert AdicaoSaldo: " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("iiss", $user_id, $valor_db, $tipo, $data_registro);
    
    if (!$stmt->execute()) {
        logAfiliacao("ERRO Execute Insert AdicaoSaldo: " . $stmt->error);
        $stmt->close();
        return false;
    }
    $stmt->close();
    
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

    // Verificação de Primeiro Depósito
    $qry_count = "SELECT COUNT(*) as total FROM transacoes WHERE usuario = ? AND status = 'pago'";
    $stmt = $mysqli->prepare($qry_count);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    
    if ($row['total'] > 1) {
        logAfiliacao("Usuário já possui " . $row['total'] . " depósitos pagos. CPA ignorado (paga apenas no primeiro).");
        return true; 
    }

    return processarCpa($user_id, $valor_deposito);
}
