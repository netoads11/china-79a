<?php
date_default_timezone_set("America/Sao_Paulo");

if (!defined('SITE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    define('SITE_URL', $protocol . $host);
}

if (!defined('DATABASE_LOADED')) {
    $bd = array(
        'local' => 'f14op9j7luzbm52gfvvaml82',
        'usuario' => '79a2',
        'senha' => '79a279a279a2',
        'banco' => '79a2'
    );

    // Try connecting with configured credentials
    try {
        $mysqli = new mysqli($bd['local'], $bd['usuario'], $bd['senha'], $bd['banco']);
    } catch (Exception $e) {
        // Fallback or error handling if needed
        error_log("Database connection error: " . $e->getMessage());
        echo json_encode([
            'status' => 'error', 
            'message' => 'Erro: Falha na conexão com o banco de dados.'
        ]);
        exit;
    }

    if ($mysqli->connect_errno) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Erro: Arquivo de configuração do banco não encontrado.'
        ]);
        exit;
    }

    if (!$mysqli->set_charset("utf8mb4")) {
        $mysqli->set_charset("utf8");
    }
    
    // Check for table collation only if connection is successful
    try {
        $res = $mysqli->query("SELECT T.table_collation FROM information_schema.TABLES T WHERE T.table_schema = DATABASE() AND T.table_name = 'config' LIMIT 1");
        if ($res) {
            $row = $res->fetch_assoc();
            if ($row && isset($row['table_collation']) && strpos($row['table_collation'], 'utf8mb4') === false) {
                $mysqli->query("ALTER TABLE `config` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        }
    } catch (Exception $e) {
        // Ignore collation check errors
    }
    
    define('DATABASE_LOADED', true);
}
?>