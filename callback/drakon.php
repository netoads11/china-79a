<?php
//ini_set('display_errors', 1);
//error_reporting(E_ALL);

header('Content-Type: application/json');

include_once "../config.php";
include_once "../" . DASH . "/services-prod/prod.php";
include_once "../" . DASH . "/services/database.php";
include_once "../" . DASH . "/services/funcao.php";
include_once "../" . DASH . "/services/crud.php";

// Log da requisição completa
$input = file_get_contents('php://input');
error_log("[DRAKON_CALLBACK] Raw Request: $input");
error_log("[DRAKON_CALLBACK] Headers: " . json_encode(getallheaders()));

$req = json_decode($input, true);
if (!is_array($req)) {
    error_log("[DRAKON_CALLBACK] Invalid JSON payload");
    echo json_encode(["status" => 0, "msg" => "INVALID_PAYLOAD"]);
    exit;
}

$method = isset($req['method']) ? $req['method'] : '';
error_log("[DRAKON_CALLBACK] Method: $method | Full Request: " . json_encode($req));

// account_details
if ($method === 'account_details') {
    $user_id = isset($req['user_id']) ? intval($req['user_id']) : 0;
    $user_code = isset($req['user_code']) ? $req['user_code'] : '';
    
    if ($user_id > 0) {
        $stmt = $mysqli->prepare("SELECT id, mobile, real_name FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
    } elseif (!empty($user_code)) {
        $stmt = $mysqli->prepare("SELECT id, mobile, real_name FROM usuarios WHERE mobile = ? LIMIT 1");
        $stmt->bind_param("s", $user_code);
    } else {
        echo json_encode(["status" => 0, "msg" => "MISSING_USER_IDENTIFIER"]);
        exit;
    }
    
    if ($stmt && $stmt->execute()) {
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $response = [
                "status" => 1,
                "user_id" => (string)$row['id'],
                "email" => $row['mobile'],
                "name_jogador" => !empty($row['real_name']) ? $row['real_name'] : $row['mobile']
            ];
            error_log("[DRAKON_CALLBACK] account_details success: " . json_encode($response));
            echo json_encode($response);
            exit;
        }
    }
    error_log("[DRAKON_CALLBACK] User not found");
    echo json_encode(["status" => 0, "msg" => "USER_NOT_FOUND"]);
    exit;
}

// user_balance
if ($method === 'user_balance') {
    $user_id = isset($req['user_id']) ? intval($req['user_id']) : 0;
    $user_code = isset($req['user_code']) ? $req['user_code'] : '';
    
    if ($user_id > 0) {
        $stmt = $mysqli->prepare("SELECT saldo FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
    } elseif (!empty($user_code)) {
        $stmt = $mysqli->prepare("SELECT saldo FROM usuarios WHERE mobile = ? LIMIT 1");
        $stmt->bind_param("s", $user_code);
    } else {
        echo json_encode(["status" => 0, "msg" => "MISSING_USER_IDENTIFIER"]);
        exit;
    }
    
    if ($stmt && $stmt->execute()) {
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $balance = number_format((float)$row['saldo'], 2, '.', '');
            error_log("[DRAKON_CALLBACK] user_balance: $balance");
            echo json_encode(["status" => 1, "balance" => $balance]);
            exit;
        }
    }
    error_log("[DRAKON_CALLBACK] User not found for balance");
    echo json_encode(["status" => 0, "msg" => "USER_NOT_FOUND"]);
    exit;
}

// transaction_bet
if ($method === 'transaction_bet') {
    $user_id = isset($req['user_id']) ? intval($req['user_id']) : 0;
    $amount = isset($req['amount']) ? (float)$req['amount'] : (isset($req['bet_money']) ? (float)$req['bet_money'] : 0.0);
    
    error_log("[DRAKON_CALLBACK] transaction_bet - User: $user_id, Amount: $amount");
    
    if ($user_id <= 0 || $amount <= 0) {
        echo json_encode(["status" => 0, "msg" => "INVALID_FIELDS"]);
        exit;
    }
    
    $stmt = $mysqli->prepare("SELECT saldo FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    
    if (!$stmt->execute()) {
        error_log("[DRAKON_CALLBACK] Query error: " . $mysqli->error);
        echo json_encode(["status" => 0, "msg" => "ERROR_QUERY"]);
        exit;
    }
    
    $res = $stmt->get_result();
    if (!$row = $res->fetch_assoc()) {
        echo json_encode(["status" => 0, "msg" => "USER_NOT_FOUND"]);
        exit;
    }
    
    $saldo = (float)$row['saldo'];
    
    if ($amount > $saldo) {
        error_log("[DRAKON_CALLBACK] Insufficient balance. Required: $amount, Available: $saldo");
        echo json_encode([
            "status" => 0, 
            "msg" => "INSUFFICIENT_BALANCE", 
            "balance" => number_format($saldo, 2, '.', '')
        ]);
        exit;
    }
    
    $novo = $saldo - $amount;
    $up = $mysqli->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
    $up->bind_param("di", $novo, $user_id);
    
    if ($up->execute()) {
        error_log("[DRAKON_CALLBACK] Bet successful. New balance: $novo");
        echo json_encode(["status" => 1, "balance" => number_format($novo, 2, '.', '')]);
        exit;
    }
    
    error_log("[DRAKON_CALLBACK] Update failed: " . $mysqli->error);
    echo json_encode(["status" => 0, "msg" => "UPDATE_FAILED"]);
    exit;
}

// transaction_win
if ($method === 'transaction_win') {
    $user_id = isset($req['user_id']) ? intval($req['user_id']) : 0;
    $amount = isset($req['amount']) ? (float)$req['amount'] : (isset($req['win_money']) ? (float)$req['win_money'] : 0.0);
    
    error_log("[DRAKON_CALLBACK] transaction_win - User: $user_id, Amount: $amount");
    
    if ($user_id <= 0 || $amount < 0) {
        echo json_encode(["status" => 0, "msg" => "INVALID_FIELDS"]);
        exit;
    }
    
    $stmt = $mysqli->prepare("SELECT saldo FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    
    if (!$stmt->execute()) {
        echo json_encode(["status" => 0, "msg" => "ERROR_QUERY"]);
        exit;
    }
    
    $res = $stmt->get_result();
    if (!$row = $res->fetch_assoc()) {
        echo json_encode(["status" => 0, "msg" => "USER_NOT_FOUND"]);
        exit;
    }
    
    $novo = (float)$row['saldo'] + $amount;
    $up = $mysqli->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
    $up->bind_param("di", $novo, $user_id);
    
    if ($up->execute()) {
        error_log("[DRAKON_CALLBACK] Win successful. New balance: $novo");
        echo json_encode(["status" => 1, "balance" => number_format($novo, 2, '.', '')]);
        exit;
    }
    
    echo json_encode(["status" => 0, "msg" => "UPDATE_FAILED"]);
    exit;
}

// refund
if ($method === 'refund') {
    $user_id = isset($req['user_id']) ? intval($req['user_id']) : 0;
    $amount = isset($req['amount']) ? (float)$req['amount'] : 0.0;
    
    error_log("[DRAKON_CALLBACK] refund - User: $user_id, Amount: $amount");
    
    if ($user_id <= 0 || $amount <= 0) {
        echo json_encode(["status" => 0, "msg" => "INVALID_FIELDS"]);
        exit;
    }
    
    $stmt = $mysqli->prepare("SELECT saldo FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    
    if (!$stmt->execute()) {
        echo json_encode(["status" => 0, "msg" => "ERROR_QUERY"]);
        exit;
    }
    
    $res = $stmt->get_result();
    if (!$row = $res->fetch_assoc()) {
        echo json_encode(["status" => 0, "msg" => "USER_NOT_FOUND"]);
        exit;
    }
    
    $novo = (float)$row['saldo'] + $amount;
    $up = $mysqli->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
    $up->bind_param("di", $novo, $user_id);
    
    if ($up->execute()) {
        error_log("[DRAKON_CALLBACK] Refund successful. New balance: $novo");
        echo json_encode(["status" => 1, "balance" => number_format($novo, 2, '.', '')]);
        exit;
    }
    
    echo json_encode(["status" => 0, "msg" => "UPDATE_FAILED"]);
    exit;
}

error_log("[DRAKON_CALLBACK] Method not supported: $method");
echo json_encode(["status" => 0, "msg" => "METHOD_NOT_SUPPORTED"]);