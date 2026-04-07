<?php
include_once __DIR__ . "/database.php";
include_once __DIR__ . "/funcao.php";
function checa_login_adm() {
    global $mysqli;
    // Carrega data_adm da sessão ou do banco diretamente
    if (!isset($_SESSION["data_adm"])) {
        $result = $mysqli->query("SELECT * FROM admin_users WHERE status = 1 LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $_SESSION["data_adm"] = $result->fetch_assoc();
        }
    }
}
