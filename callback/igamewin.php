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
const LOG_FILE = __DIR__ . '/webhook.log';

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

function authenticateAgent($agentCode, $agentToken) {
    global $mysqli;
    
    if (empty($agentCode) || empty($agentToken)) {
        writeLog("ERRO: Credenciais ausentes");
        return false;
    }
    
    $query = "SELECT id FROM igamewin WHERE id = 1 AND agent_code = ? AND agent_token = ?";
    $stmt = $mysqli->prepare($query);
    
    if (!$stmt) {
        writeLog("ERRO SQL prepare (auth): " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("ss", $agentCode, $agentToken);
    $stmt->execute();
    $stmt->store_result();
    
    $isValid = $stmt->num_rows > 0;
    $stmt->close();
    
    if ($isValid) {
        writeLog("AUTENTICAÇÃO OK: agent_code=$agentCode");
    } else {
        writeLog("FALHA: Autenticação inválida para agent_code=$agentCode");
    }
    
    return $isValid;
}

function handleUserBalance($request) {
    global $mysqli;
    
    writeLog("INICIANDO handleUserBalance");
    
    $userCode = $request['user_code'] ?? '';
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
    $balance = $row['saldo'];
    
    writeLog("SALDO ENCONTRADO: $balance");
    $stmt->close();
    
    return ['status' => 1, 'user_balance' => floatval($balance)];
}

function handleTransaction($request) {
    global $mysqli;
    
    writeLog("INICIANDO handleTransaction");
    
    $userCode = $request['user_code'] ?? '';
    $gameType = $request['game_type'] ?? '';
    $slotData = $request['slot'] ?? [];
    $providerCode = $slotData['provider_code'] ?? '';
    $gameCode = $slotData['game_code'] ?? '';
    $betMoney = $slotData['bet_money'] ?? 0;
    $winMoney = $slotData['win_money'] ?? 0;
    $txnId = $slotData['txn_id'] ?? '';
    $txnType = $slotData['txn_type'] ?? '';
    
    writeLog("user_code=$userCode | bet=$betMoney | win=$winMoney | txn_id=$txnId");
    
    if (empty($userCode) || empty($txnId)) {
        return ['status' => 0, 'msg' => 'REQUIRED_FIELDS_MISSING'];
    }
    
    $userData = getUserData($userCode);
    if (!$userData) {
        writeLog("USUÁRIO INVÁLIDO: $userCode");
        return ['status' => 0, 'msg' => 'INVALID_USER', 'user_code' => $userCode];
    }
    
    $userId = $userData['id'];
    $currentBalance = $userData['saldo'];
    $newBalance = $currentBalance - $betMoney + $winMoney;
    
    writeLog("ID_USER: $userId | SALDO ATUAL: $currentBalance | NOVO SALDO: $newBalance");
    
    if ($betMoney > $currentBalance) {
        writeLog("SALDO INSUFICIENTE: bet=$betMoney, saldo=$currentBalance");
        return ['status' => 0, 'msg' => 'INSUFFICIENT_BALANCE'];
    }
    
    $mysqli->autocommit(false);
    
    try {
        if (!insertGameHistory($userId, $gameCode, $betMoney, $winMoney, $txnId)) {
            throw new Exception("Erro ao inserir histórico");
        }
        
        if (!updateUserBalance($userId, $newBalance)) {
            throw new Exception("Erro ao atualizar saldo");
        }
        
        $mysqli->commit();
        writeLog("TRANSAÇÃO CONCLUÍDA COM SUCESSO");
        
        return ['status' => 1, 'user_balance' => floatval($newBalance)];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        writeLog("ERRO NA TRANSAÇÃO: " . $e->getMessage());
        return ['status' => 0, 'msg' => 'TRANSACTION_FAILED'];
    } finally {
        $mysqli->autocommit(true);
    }
}

function getUserData($userCode) {
    global $mysqli;
    
    $query = "SELECT id, saldo FROM usuarios WHERE mobile = ?";
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

function insertGameHistory($userId, $gameCode, $betMoney, $winMoney, $txnId) {
    global $mysqli;
    
    $query = "INSERT INTO historico_play (id_user, nome_game, bet_money, win_money, txn_id, created_at, status_play) 
              VALUES (?, ?, ?, ?, ?, NOW(), 1)";
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        writeLog("ERRO SQL prepare (insertGameHistory): " . $mysqli->error);
        return false;
    }
    
    $stmt->bind_param("isdds", $userId, $gameCode, $betMoney, $winMoney, $txnId);
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
        writeLog("SALDO ATUALIZADO COM SUCESSO");
    }
    
    $stmt->close();
    return $success;
}

$rawInput = file_get_contents('php://input');
writeLog("RAW RECEIVED: " . $rawInput);

$requestData = json_decode($rawInput, true);
if (!$requestData) {
    writeLog("JSON MALFORMADO: " . json_last_error_msg());
    sendErrorResponse(400, 0, 'Invalid JSON');
}

writeLog("JSON DECODED: " . json_encode($requestData));

$agentCode = $requestData['agent_code'] ?? '';
$agentToken = $requestData['agent_token'] ?? $requestData['agent_secret'] ?? '';

if (!authenticateAgent($agentCode, $agentToken)) {
    sendErrorResponse(401, 0, 'INVALID POST');
}

$method = $requestData['method'] ?? '';
if (empty($method)) {
    writeLog("FALHA: method não especificado");
    sendErrorResponse(400, 0, 'Method not specified');
}

writeLog("PROCESSANDO MÉTODO: $method");

switch ($method) {
    case 'user_balance':
        $response = handleUserBalance($requestData);
        writeLog("RESPOSTA USER_BALANCE: " . json_encode($response));
        sendSuccessResponse($response);
        break;
        
    case 'transaction':
        $response = handleTransaction($requestData);
        writeLog("RESPOSTA TRANSACTION: " . json_encode($response));
        sendSuccessResponse($response);
        break;
        
    default:
        writeLog("MÉTODO NÃO SUPORTADO: $method");
        sendErrorResponse(400, 0, 'Method not supported');
}
?>