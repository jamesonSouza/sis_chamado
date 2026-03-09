<?php
session_start();

// ==========================================
// CONFIGURAÇÕES DO BANCO DE DADOS
// ==========================================
$db_host = 'localhost';
$db_name = 'sistema_chamados'; // Crie este banco no seu MySQL (CREATE DATABASE sistema_chamados;)
$db_user = '';
$db_pass = ''; // Senha do seu banco (deixe vazio no XAMPP padrão)

try {
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Criar banco se não existir e conectar
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

    // ==========================================
    // CRIAÇÃO DAS TABELAS (Auto-Setup)
    // ==========================================
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tipos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS departamento (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS problema_solucao (
        id INT AUTO_INCREMENT PRIMARY KEY,
        descricao_problema TEXT NOT NULL,
        data_abertura DATETIME NOT NULL,
        departamento_id INT NOT NULL,
        tipo_id INT NOT NULL,
        usuario_id INT NOT NULL,
        descricao_solucao TEXT,
        data_resolucao DATETIME,
        status ENUM('Aberto', 'Resolvido') DEFAULT 'Aberto',
        FOREIGN KEY (departamento_id) REFERENCES departamento(id),
        FOREIGN KEY (tipo_id) REFERENCES tipos(id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");

} catch (PDOException $e) {
    die("Erro de Conexão com o Banco de Dados: " . $e->getMessage() . "<br><br>Verifique as credenciais no arquivo de conexão.");
}
?>