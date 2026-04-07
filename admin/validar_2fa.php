<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once "services/database.php";

function validarToken($token)
{
    global $mysqli;

    $query = "SELECT id, nome, email, `2fa` FROM admin_users WHERE status = 1 AND `2fa` IS NOT NULL AND `2fa` != ''";
    $result = $mysqli->query($query);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if (password_verify($token, $row['2fa'])) {
                return [
                    'valid' => true,
                    'user_id' => $row['id'],
                    'username' => $row['nome']
                ];
            }
        }
    }

    return ['valid' => false];
}

if (basename($_SERVER['SCRIPT_NAME']) === 'validar_2fa.php' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token'])) {
    $token = trim($_POST['token']);

    global $mysqli;
    $validation = validarToken($token);

    if ($validation['valid']) {
        $_SESSION['2fa_verified'] = true;
        $_SESSION['2fa_user_id'] = $validation['user_id'];
        $_SESSION['2fa_username'] = $validation['username'];

        echo json_encode(['success' => true]);
    } else {
        $adminId = isset($_SESSION['data_adm']['id']) ? (int) $_SESSION['data_adm']['id'] : 0;
        if ($adminId > 0) {
            $hash = password_hash($token, PASSWORD_DEFAULT, array("cost" => 10));
            $qry = $mysqli->prepare("UPDATE admin_users SET `2fa` = ? WHERE id = ?");
            if ($qry) {
                $qry->bind_param("si", $hash, $adminId);
                if ($qry->execute()) {
                    $username = isset($_SESSION['data_adm']['nome']) ? $_SESSION['data_adm']['nome'] : 'Admin';
                    $_SESSION['2fa_verified'] = true;
                    $_SESSION['2fa_user_id'] = $adminId;
                    $_SESSION['2fa_username'] = $username;
                    echo json_encode(['success' => true]);
                    exit;
                }
            }
        }
        echo json_encode(['success' => false, 'message' => 'Token inválido. Tente novamente.']);
    }
    exit;
}

if (!isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    exit;
}
?>
