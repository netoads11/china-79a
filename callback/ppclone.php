<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* Dependencias Da Api */
include_once "../config.php";
include_once "../" . DASH . "/services-prod/prod.php";
include_once "../" . DASH . "/services/database.php";
include_once "../" . DASH . "/services/funcao.php";
include_once "../" . DASH . "/services/crud.php";

const ENABLE_LOGS = false;
const LOG_FILE = __DIR__ . '/ppclone_webhook.log';

function writeLog($message) {
    if (!ENABLE_LOGS) return;
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
}

function sendErrorResponse($httpCode, $status, $message, $extraData = []) {
    http_response_code($httpCode);
    $response = array_merge(['status' => $status, 'msg' => $message], $extraData);
    echo json_encode($response);
    exit;
}

function sendSuccessResponse($data) {
    echo json_encode($data);
    exit;
}

function authenticateAgent($agentSecret) {
    global $mysqli;
    
    // Limpar espaços em branco e quebras de linha
    $agentSecret = trim($agentSecret);
    
    if (empty($agentSecret)) {
        writeLog("ERRO PPClone: Agent secret ausente");
        return false;
    }
    
    writeLog("Tentando autenticar com agent_secret: " . $agentSecret);
    
    $query = "SELECT agent_secret FROM ppclone WHERE id = 1 AND ativo = 1";
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        writeLog("ERRO SQL prepare (auth PPClone): " . $mysqli->error);
        return false;
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $dbSecret = trim($row['agent_secret']); // Limpar o valor do banco também
        
        writeLog("Agent secret do banco: " . $dbSecret);
        writeLog("Agent secret recebido: " . $agentSecret);
        
        if ($agentSecret === $dbSecret) {
            writeLog("AUTENTICAÇÃO PPClone OK");
            $stmt->close();
            return true;
        } else {
            writeLog("FALHA: Agent secrets não conferem");
        }
    } else {
        writeLog("FALHA: Nenhum registro encontrado na tabela ppclone");
    }
    
    $stmt->close();
    return false;
}

function handleUserBalance($request) {
    global $mysqli;
    
    writeLog("INICIANDO handleUserBalance PPClone");
    
    $userCode = trim($request['user_code'] ?? '');
    writeLog("USER_CODE: $userCode");
    
    if (empty($userCode)) {
        return ['status' => 0, 'msg' => 'USER_CODE_REQUIRED'];
    }
    
    $query = "SELECT saldo FROM usuarios WHERE mobile = ?";
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        $error = $mysqli->error;
        writeLog("ERRO SQL prepare: $error");
        return ['status' => 0, 'msg' => 'ERROR_PREPARING_QUERY', 'sql_error' => $error];
    }
    
    $stmt->bind_param("s", $userCode);
    
    if (!$stmt->execute()) {
        $sqlError = $stmt->error;
        writeLog("ERRO NA EXECUÇÃO DO SELECT: $sqlError");
        return ['status' => 0, 'msg' => 'ERROR_QUERY', 'error' => $sqlError];
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        writeLog("USUÁRIO NÃO ENCONTRADO: $userCode");
        return ['status' => 0, 'msg' => 'INVALID_USER', 'user_code' => $userCode];
    }
    
    $row = $result->fetch_assoc();
    $balance = floatval($row['saldo']);
    
    writeLog("SALDO ENCONTRADO: $balance para user: $userCode");
    $stmt->close();
    
    return ['status' => 1, 'user_balance' => $balance];
}

function handleTransaction($request) {
    global $mysqli;
    
    writeLog("INICIANDO handleTransaction PPClone");
    
    $userCode = trim($request['user_code'] ?? '');
    $gameType = trim($request['game_type'] ?? 'slot');
    $slotData = $request[$gameType] ?? [];
    
    $gameCode = trim($slotData['game_code'] ?? '');
    $bet = floatval($slotData['bet_money'] ?? 0);
    $win = floatval($slotData['win_money'] ?? 0);
    $txnId = trim($slotData['txn_id'] ?? '');
    
    writeLog("PPClone Transaction: user=$userCode | game=$gameCode | bet=$bet | win=$win | txn_id=$txnId");
    
    if (empty($userCode) || empty($txnId)) {
        writeLog("CAMPOS OBRIGATÓRIOS FALTANDO");
        return ['status' => 0, 'msg' => 'REQUIRED_FIELDS_MISSING'];
    }
    
    // Buscar dados do usuário
    $userData = getUserData($userCode);
    if (!$userData) {
        writeLog("USUÁRIO INVÁLIDO: $userCode");
        return ['status' => 0, 'msg' => 'INVALID_USER', 'user_code' => $userCode];
    }
    
    $userId = $userData['id'];
    $currentBalance = floatval($userData['saldo']);
    $invitationCode = $userData['invitation_code'] ?? '';
    
    writeLog("ID_USER: $userId | SALDO ATUAL: $currentBalance");
    
    // Verificar transação duplicada
    if (isTransactionDuplicate($txnId)) {
        writeLog("TRANSAÇÃO DUPLICADA: $txnId");
        return ['status' => 1, 'msg' => 'TRANSACTION_ALREADY_PROCESSED', 'user_balance' => $currentBalance];
    }
    
    // ✅ CORREÇÃO: Calcular ganho líquido como na API38
    $ganho = floatval($win) - floatval($bet);
    $newBalance = floatval($currentBalance) + $ganho;
    
    writeLog("CÁLCULO: win=$win - bet=$bet = ganho_liquido=$ganho | saldo_anterior=$currentBalance + ganho=$ganho = novo_saldo=$newBalance");
    
    // Verificar se tem saldo suficiente
    if ($newBalance < 0) {
        writeLog("SALDO INSUFICIENTE");
        return ['status' => 0, 'msg' => 'INSUFFICIENT_BALANCE'];
    }
    
    // Iniciar transação
    $mysqli->autocommit(false);
    
    try {
        // Inserir histórico
        if (!insertGameHistory($userId, $gameCode, $bet, $win, $txnId)) {
            throw new Exception("Erro ao inserir histórico");
        }
        
        // Atualizar saldo
        if (!updateUserBalance($userId, $newBalance)) {
            throw new Exception("Erro ao atualizar saldo");
        }
        
        // Processar comissão de afiliado (se houver)
        if (!empty($invitationCode)) {
            processAffiliateCommission($invitationCode, $bet, $win);
        }
        
        $mysqli->commit();
        writeLog("TRANSAÇÃO PPClone CONCLUÍDA COM SUCESSO");
        
        return ['status' => 1, 'user_balance' => floatval($newBalance)];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        writeLog("ERRO NA TRANSAÇÃO PPClone: " . $e->getMessage());
        return ['status' => 0, 'msg' => 'TRANSACTION_FAILED', 'error' => $e->getMessage()];
    } finally {
        $mysqli->autocommit(true);
    }
}

function getUserData($userCode) {
    global $mysqli;
    
    $query = "SELECT id, saldo, invitation_code FROM usuarios WHERE mobile = ?";
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        writeLog("ERRO SQL prepare (getUserData): " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("s", $userCode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return false;
    }
    
    $userData = $result->fetch_assoc();
    $stmt->close();
    
    return $userData;
}

function isTransactionDuplicate($txnId) {
    global $mysqli;
    
    $query = "SELECT id FROM historico_play WHERE txn_id = ? LIMIT 1";
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        writeLog("ERRO SQL prepare (isTransactionDuplicate): " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("s", $txnId);
    $stmt->execute();
    $stmt->store_result();
    
    $isDuplicate = $stmt->num_rows > 0;
    $stmt->close();
    
    return $isDuplicate;
}

function insertGameHistory($userId, $gameCode, $bet, $win, $txnId) {
    global $mysqli;
    
    $query = "INSERT INTO historico_play (id_user, nome_game, bet_money, win_money, txn_id, created_at, status_play) 
              VALUES (?, ?, ?, ?, ?, NOW(), 1)";
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        writeLog("ERRO SQL prepare (insertGameHistory): " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("isdds", $userId, $gameCode, $bet, $win, $txnId);
    $success = $stmt->execute();
    
    if (!$success) {
        writeLog("ERRO ao inserir histórico: " . $stmt->error);
    } else {
        writeLog("HISTÓRICO INSERIDO COM SUCESSO");
    }
    
    $stmt->close();
    return $success;
}

function updateUserBalance($userId, $newBalance) {
    global $mysqli;
    
    $query = "UPDATE usuarios SET saldo = ? WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        writeLog("ERRO SQL prepare (updateUserBalance): " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("di", $newBalance, $userId);
    $success = $stmt->execute();
    
    if (!$success) {
        writeLog("ERRO ao atualizar saldo: " . $stmt->error);
    } else {
        writeLog("SALDO ATUALIZADO COM SUCESSO: $newBalance");
    }
    
    $stmt->close();
    return $success;
}

function processAffiliateCommission($invitationCode, $bet, $win) {
    global $mysqli;
    
    writeLog("Processando comissão de afiliado para: $invitationCode");
    
    // Buscar afiliado
    $query = "SELECT id, tipo_pagamento FROM usuarios WHERE invite_code = ? LIMIT 1";
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        writeLog("ERRO ao buscar afiliado: " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("s", $invitationCode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        writeLog("Afiliado não encontrado: $invitationCode");
        $stmt->close();
        return false;
    }
    
    $afiliado = $result->fetch_assoc();
    $stmt->close();
    
    // Verificar se o afiliado recebe RevShare
    if ($afiliado['tipo_pagamento'] == 2 || $afiliado['tipo_pagamento'] == 0) {
        $data_afiliados = data_afiliados_cpa_rev();
        
        if (isset($data_afiliados['revShareLvl1'])) {
            $porcentagem = $data_afiliados['revShareLvl1'] / 100;
            
            // ✅ CORREÇÃO: Calcular comissão sobre o ganho líquido
            $ganho_liquido = floatval($win) - floatval($bet);
            $comissao = $ganho_liquido * $porcentagem;
            
            writeLog("Comissão calculada: $comissao (RevShare: {$data_afiliados['revShareLvl1']}% sobre ganho_liquido=$ganho_liquido)");
            
            // Só processa comissão positiva
            if ($comissao > 0) {
                // Atualizar saldo do afiliado
                $updateQuery = "UPDATE usuarios SET saldo = saldo + ?, rev = rev + ?, total_rev = total_rev + ? WHERE invite_code = ?";
                $stmtUpdate = $mysqli->prepare($updateQuery);
                
                if ($stmtUpdate) {
                    $stmtUpdate->bind_param("ddds", $comissao, $comissao, $comissao, $invitationCode);
                    if ($stmtUpdate->execute()) {
                        writeLog("Comissão de afiliado processada com sucesso: $comissao");
                    } else {
                        writeLog("ERRO ao atualizar comissão: " . $stmtUpdate->error);
                    }
                    $stmtUpdate->close();
                }
            } else {
                writeLog("Comissão não processada (valor negativo ou zero): $comissao");
            }
        }
    }
    
    return true;
}

// ========== PROCESSAMENTO DA REQUISIÇÃO ==========

$rawInput = file_get_contents('php://input');
writeLog("RAW RECEIVED PPClone: " . $rawInput);

$requestData = json_decode($rawInput, true);
if (!$requestData) {
    writeLog("JSON MALFORMADO: " . json_last_error_msg());
    sendErrorResponse(400, 0, 'Invalid JSON');
}

// Limpar todos os valores recebidos
array_walk_recursive($requestData, function(&$value) {
    if (is_string($value)) {
        $value = trim($value);
    }
});

writeLog("JSON DECODED (após limpeza): " . json_encode($requestData));

// Autenticação (PPClone usa agent_secret)
$agentSecret = $requestData['agent_secret'] ?? '';

if (!authenticateAgent($agentSecret)) {
    sendErrorResponse(401, 0, 'INVALID_CREDENTIALS');
}

$method = $requestData['method'] ?? '';
if (empty($method)) {
    writeLog("FALHA: method não especificado");
    sendErrorResponse(400, 0, 'Method not specified');
}

writeLog("PROCESSANDO MÉTODO PPClone: $method");

switch ($method) {
    case 'user_balance':
        $response = handleUserBalance($requestData);
        writeLog("RESPOSTA USER_BALANCE PPClone: " . json_encode($response));
        sendSuccessResponse($response);
        break;
        
    case 'transaction':
    case 'game_callback':
        $response = handleTransaction($requestData);
        writeLog("RESPOSTA TRANSACTION PPClone: " . json_encode($response));
        sendSuccessResponse($response);
        break;
        
    default:
        writeLog("MÉTODO NÃO SUPORTADO: $method");
        sendErrorResponse(400, 0, 'Method not supported');
}
?>