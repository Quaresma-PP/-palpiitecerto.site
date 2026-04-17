<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'japadaloto_analytics');
define('DB_USER', 'jap_analytics');
define('DB_PASS', 'J4p@n4lyT1cs_2026!sEcuR3');

define('PANEL_PASSWORD', 'japa2026painel');

define('FUNNEL_PAGES', [
    'etapa1-vsl'     => 'Etapa 1 — VSL 01',
    'etapa2-escolher'=> 'Etapa 2 — Escolher Loteria',
    'etapa3-vsl'     => 'Etapa 3 — VSL 02',
    'etapa4-quiz'    => 'Etapa 4 — Quiz',
    'etapa5-vsl'     => 'Etapa 5 — VSL 03',
]);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ]
        );
    }
    return $pdo;
}
