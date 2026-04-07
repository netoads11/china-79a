<?php
include_once __DIR__ . '/database.php';
include_once __DIR__ . '/funcao.php';
function checa_login_adm()
{
    global $painel_adm_acessar, $mysqli, $painel_adm;

    if (!isset($_SESSION['token_adm_encrypted']) || !isset($_SESSION['crsf_token_adm']) || !isset($_SESSION['anti_crsf_token_adm'])) {
        session_destroy();
        header('Location: ' . $painel_adm_acessar);
        exit();
    }

    $view_id = CRIPT_AES('decrypt', $_SESSION['token_adm_encrypted']);
    $safe_id  = intval($view_id);

    if ($safe_id <= 0) {
        session_destroy();
        header('Location: ' . $painel_adm_acessar);
        exit();
    }

    $stmt = $mysqli->prepare("SELECT * FROM admin_users WHERE id = ? AND status = 1 LIMIT 1");
    $stmt->bind_param('i', $safe_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        session_destroy();
        header('Location: ' . $painel_adm_acessar);
        exit();
    }

    $_SESSION['data_adm'] = $result->fetch_assoc();
}
