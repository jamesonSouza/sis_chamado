<?php
session_start();
require_once 'conexao.php';

// ==========================================
// ROTEAMENTO E AÇÕES (POST / GET)
// ==========================================
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'novo_chamado') {
                $stmt = $pdo->prepare("INSERT INTO problema_solucao (descricao_problema, data_abertura, departamento_id, tipo_id, usuario_id, status) VALUES (?, NOW(), ?, ?, ?, 'Aberto')");
                $stmt->execute([$_POST['descricao'], $_POST['departamento_id'], $_POST['tipo_id'], $_POST['usuario_id']]);
                $msg = "Chamado aberto com sucesso!";
                $msgType = "success";
            } 
            elseif ($_POST['action'] == 'resolver_chamado') {
                $stmt = $pdo->prepare("UPDATE problema_solucao SET descricao_solucao = ?, data_resolucao = NOW(), status = 'Resolvido' WHERE id = ?");
                $stmt->execute([$_POST['solucao'], $_POST['chamado_id']]);
                $msg = "Chamado resolvido com sucesso!";
                $msgType = "success";
            }
            elseif ($_POST['action'] == 'cadastrar_basico') {
                $tabela = $_POST['tabela']; // usuarios, tipos ou departamento
                $nome = $_POST['nome'];
                if(in_array($tabela, ['usuarios', 'tipos', 'departamento'])) {
                    $stmt = $pdo->prepare("INSERT INTO $tabela (nome) VALUES (?)");
                    $stmt->execute([$nome]);
                    $msg = ucfirst($tabela) . " cadastrado(a) com sucesso!";
                    $msgType = "success";
                }
            }
            elseif ($_POST['action'] == 'excluir_basico') {
                $tabela = $_POST['tabela'];
                $id = $_POST['id'];
                if(in_array($tabela, ['usuarios', 'tipos', 'departamento'])) {
                    $coluna_fk = '';
                    if ($tabela == 'usuarios') $coluna_fk = 'usuario_id';
                    if ($tabela == 'tipos') $coluna_fk = 'tipo_id';
                    if ($tabela == 'departamento') $coluna_fk = 'departamento_id';
                    
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM problema_solucao WHERE $coluna_fk = ?");
                    $stmtCheck->execute([$id]);
                    if ($stmtCheck->fetchColumn() > 0) {
                        $msg = "Não é possível excluir: existem chamados vinculados a este item.";
                        $msgType = "error";
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM $tabela WHERE id = ?");
                        $stmt->execute([$id]);
                        $msg = ucfirst($tabela) . " excluído(a) com sucesso!";
                        $msgType = "success";
                    }
                }
            }
        } catch (Exception $e) {
            $msg = "Erro na operação: " . $e->getMessage();
            $msgType = "error";
        }
    }
}

// Pega a página atual
$page = $_GET['page'] ?? 'dashboard';

// ==========================================
// LÓGICA DE EXPORTAÇÃO PARA EXCEL (CSV)
// ==========================================
if (isset($_GET['export']) && $_GET['export'] == '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=historico_chamados_'.date('Y-m-d').'.csv');
    
    // Adiciona BOM para o Excel ler acentos corretamente
    echo "\xEF\xBB\xBF"; 
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Status', 'Problema', 'Data Abertura', 'Solução', 'Data Resolução', 'Tipo', 'Departamento', 'Usuário', 'Mês', 'Ano', 'Mês e Ano'], ';');

    // Monta a query com os filtros atuais
    $where = "1=1";
    $params = [];
    if(!empty($_GET['data_inicio'])) { $where .= " AND p.data_abertura >= ?"; $params[] = $_GET['data_inicio'] . ' 00:00:00'; }
    if(!empty($_GET['data_fim'])) { $where .= " AND p.data_abertura <= ?"; $params[] = $_GET['data_fim'] . ' 23:59:59'; }
    if(!empty($_GET['departamento'])) { $where .= " AND p.departamento_id = ?"; $params[] = $_GET['departamento']; }
    if(!empty($_GET['tipo'])) { $where .= " AND p.tipo_id = ?"; $params[] = $_GET['tipo']; }
    if(!empty($_GET['usuario'])) { $where .= " AND p.usuario_id = ?"; $params[] = $_GET['usuario']; }

    $sql = "SELECT p.id, p.status, p.descricao_problema, p.data_abertura, p.descricao_solucao, p.data_resolucao, t.nome as tipo, d.nome as departamento, u.nome as usuario,
            DATE_FORMAT(p.data_abertura, '%m') as mes,
            DATE_FORMAT(p.data_abertura, '%Y') as ano,
            DATE_FORMAT(p.data_abertura, '%m/%Y') as mes_ano
            FROM problema_solucao p 
            JOIN tipos t ON p.tipo_id = t.id 
            JOIN departamento d ON p.departamento_id = d.id 
            JOIN usuarios u ON p.usuario_id = u.id 
            WHERE $where ORDER BY p.data_abertura DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row, ';');
    }
    fclose($output);
    exit;
}

// Funções auxiliares para buscar dados
function getAll($pdo, $table) { return $pdo->query("SELECT * FROM $table ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC); }
$usuarios = getAll($pdo, 'usuarios');
$tipos = getAll($pdo, 'tipos');
$departamentos = getAll($pdo, 'departamento');

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Chamados</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
    </style>
</head>
<body class="text-gray-800">

    <!-- Navbar -->
    <nav class="bg-blue-800 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-4">
                    <span class="font-bold text-xl tracking-wider">HelpDesk Plus</span>
                    <a href="?page=dashboard" class="px-3 py-2 rounded-md <?= $page == 'dashboard' ? 'bg-blue-900 font-bold' : 'hover:bg-blue-700' ?>">Análise & Dashboard</a>
                    <a href="?page=chamados" class="px-3 py-2 rounded-md <?= $page == 'chamados' ? 'bg-blue-900 font-bold' : 'hover:bg-blue-700' ?>">Fila de Chamados</a>
                    <a href="?page=pesquisa" class="px-3 py-2 rounded-md <?= $page == 'pesquisa' ? 'bg-blue-900 font-bold' : 'hover:bg-blue-700' ?>">Pesquisar</a>
                    <a href="?page=cadastros" class="px-3 py-2 rounded-md <?= $page == 'cadastros' ? 'bg-blue-900 font-bold' : 'hover:bg-blue-700' ?>">Cadastros</a>
                </div>
                <div>
                    <a href="?page=novo_chamado" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded shadow">
                        + Novo Chamado
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 py-8">

        <?php if ($msg): ?>
            <div class="mb-6 p-4 rounded-md <?= $msgType == 'success' ? 'bg-green-100 text-green-700 border border-green-400' : 'bg-red-100 text-red-700 border border-red-400' ?>">
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <?php
        // ==========================================
        // PÁGINA: DASHBOARD / HISTÓRICO
        // ==========================================
        if ($page == 'dashboard'): 
            
            // Filtros Default
            $filtro_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
            $filtro_fim = $_GET['data_fim'] ?? date('Y-m-t');
            
            $where_base = "1=1";
            $params = [];
            if(!empty($_GET['data_inicio'])) { $where_base .= " AND p.data_abertura >= ?"; $params[] = $_GET['data_inicio'] . ' 00:00:00'; }
            if(!empty($_GET['data_fim'])) { $where_base .= " AND p.data_abertura <= ?"; $params[] = $_GET['data_fim'] . ' 23:59:59'; }
            if(!empty($_GET['departamento'])) { $where_base .= " AND p.departamento_id = ?"; $params[] = $_GET['departamento']; }
            if(!empty($_GET['tipo'])) { $where_base .= " AND p.tipo_id = ?"; $params[] = $_GET['tipo']; }
            if(!empty($_GET['usuario'])) { $where_base .= " AND p.usuario_id = ?"; $params[] = $_GET['usuario']; }

            // Lógica do Mês Anterior para comparação (Diminui exatamente 1 mês dos dias filtrados)
            $data_inicio_obj = new DateTime($filtro_inicio);
            $data_fim_obj = new DateTime($filtro_fim);
            
            $data_inicio_ant = (clone $data_inicio_obj)->modify("-1 month")->format('Y-m-d');
            $data_fim_ant = (clone $data_fim_obj)->modify("-1 month")->format('Y-m-d');

            $where_ant = str_replace(
                ["p.data_abertura >= ?", "p.data_abertura <= ?"], 
                ["p.data_abertura >= '$data_inicio_ant 00:00:00'", "p.data_abertura <= '$data_fim_ant 23:59:59'"], 
                $where_base
            );

            // Queries de Dashboard
            // 1. Total atual
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM problema_solucao p WHERE $where_base");
            $stmt->execute($params);
            $total_atual = $stmt->fetchColumn();

            // 2. Total anterior (usando lógica crua para simplificar os binds de array dinâmico)
            $query_ant = "SELECT COUNT(*) FROM problema_solucao p WHERE $where_ant";
            $params_ant = [];
            if(!empty($_GET['departamento'])) { $params_ant[] = $_GET['departamento']; }
            if(!empty($_GET['tipo'])) { $params_ant[] = $_GET['tipo']; }
            if(!empty($_GET['usuario'])) { $params_ant[] = $_GET['usuario']; }
            
            $stmt_ant = $pdo->prepare($query_ant);
            $stmt_ant->execute($params_ant);
            $total_anterior = $stmt_ant->fetchColumn();

            $variacao = $total_anterior > 0 ? (($total_atual - $total_anterior) / $total_anterior) * 100 : ($total_atual > 0 ? 100 : 0);
            $cor_variacao = $variacao > 0 ? 'text-red-500' : 'text-green-500'; // Mais chamados = ruim (vermelho)

            // NOVO: Total de chamados abertos (usando os mesmos filtros)
            $stmt_abertos = $pdo->prepare("SELECT COUNT(*) FROM problema_solucao p WHERE $where_base AND p.status = 'Aberto'");
            $stmt_abertos->execute($params);
            $total_abertos = $stmt_abertos->fetchColumn();

            // 3. Top Solicitantes (Atual)
            $stmt = $pdo->prepare("SELECT u.nome, COUNT(p.id) as total FROM problema_solucao p JOIN usuarios u ON p.usuario_id = u.id WHERE $where_base GROUP BY u.id ORDER BY total DESC LIMIT 5");
            $stmt->execute($params);
            $top_usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Top Solicitantes (Anterior)
            $stmt = $pdo->prepare("SELECT u.nome, COUNT(p.id) as total FROM problema_solucao p JOIN usuarios u ON p.usuario_id = u.id WHERE $where_ant GROUP BY u.id ORDER BY total DESC LIMIT 5");
            $stmt->execute($params_ant);
            $top_usuarios_ant = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Volume por Departamento
            $stmt = $pdo->prepare("SELECT d.nome, COUNT(p.id) as total FROM problema_solucao p JOIN departamento d ON p.departamento_id = d.id WHERE $where_base GROUP BY d.id");
            $stmt->execute($params);
            $vol_depto = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 6. Volume por Tipo
            $stmt = $pdo->prepare("SELECT t.nome, COUNT(p.id) as total FROM problema_solucao p JOIN tipos t ON p.tipo_id = t.id WHERE $where_base GROUP BY t.id");
            $stmt->execute($params);
            $vol_tipo = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 7. Volume por Departamento (Anterior)
            $stmt = $pdo->prepare("SELECT d.nome, COUNT(p.id) as total FROM problema_solucao p JOIN departamento d ON p.departamento_id = d.id WHERE $where_ant GROUP BY d.id");
            $stmt->execute($params_ant);
            $vol_depto_ant = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 8. Volume por Tipo (Anterior)
            $stmt = $pdo->prepare("SELECT t.nome, COUNT(p.id) as total FROM problema_solucao p JOIN tipos t ON p.tipo_id = t.id WHERE $where_ant GROUP BY t.id");
            $stmt->execute($params_ant);
            $vol_tipo_ant = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Export URL
            $export_url = '?' . http_build_query(array_merge($_GET, ['export' => 1]));
        ?>
            <!-- Filtros -->
            <div class="bg-white p-6 rounded-lg shadow-md mb-8">
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <input type="hidden" name="page" value="dashboard">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Data Inicial</label>
                        <input type="date" name="data_inicio" value="<?= htmlspecialchars($filtro_inicio) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Data Final</label>
                        <input type="date" name="data_fim" value="<?= htmlspecialchars($filtro_fim) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Departamento</label>
                        <select name="departamento" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                            <option value="">Todos</option>
                            <?php foreach($departamentos as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (isset($_GET['departamento']) && $_GET['departamento'] == $d['id']) ? 'selected' : '' ?>><?= $d['nome'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Tipo</label>
                        <select name="tipo" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                            <option value="">Todos</option>
                            <?php foreach($tipos as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= (isset($_GET['tipo']) && $_GET['tipo'] == $t['id']) ? 'selected' : '' ?>><?= $t['nome'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Usuário</label>
                        <select name="usuario" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                            <option value="">Todos</option>
                            <?php foreach($usuarios as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= (isset($_GET['usuario']) && $_GET['usuario'] == $u['id']) ? 'selected' : '' ?>><?= $u['nome'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-grow"></div>
                    <div>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Filtrar</button>
                        <a href="<?= $export_url ?>" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded inline-block ml-2">Exportar Planilha</a>
                    </div>
                </form>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
                    <h3 class="text-gray-500 text-sm font-bold uppercase">Volume do Período Filtrado</h3>
                    <p class="text-3xl font-bold text-gray-800 mt-2"><?= $total_atual ?></p>
                </div>
                <div class="bg-white p-6 rounded-lg shadow border-l-4 border-gray-400">
                    <h3 class="text-gray-500 text-sm font-bold uppercase">Período Anterior Igual</h3>
                    <p class="text-3xl font-bold text-gray-800 mt-2"><?= $total_anterior ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= date('d/m/Y', strtotime($data_inicio_ant)) ?> a <?= date('d/m/Y', strtotime($data_fim_ant)) ?></p>
                </div>
                <div class="bg-white p-6 rounded-lg shadow border-l-4 <?= $variacao > 0 ? 'border-red-500' : 'border-green-500' ?>">
                    <h3 class="text-gray-500 text-sm font-bold uppercase">Variação</h3>
                    <p class="text-3xl font-bold <?= $cor_variacao ?> mt-2">
                        <?= $variacao > 0 ? '+' : '' ?><?= number_format($variacao, 1, ',', '.') ?>%
                    </p>
                </div>
                <div class="bg-white p-6 rounded-lg shadow border-l-4 border-yellow-500">
                    <h3 class="text-gray-500 text-sm font-bold uppercase">Chamados Abertos</h3>
                    <a href="?page=chamados" class="inline-block text-3xl font-bold text-gray-800 hover:text-blue-600 mt-2 transition-colors cursor-pointer" title="Ver fila de chamados">
                        <?= $total_abertos ?> <span class="text-sm font-normal text-blue-500 underline ml-1">ver fila ➜</span>
                    </a>
                </div>
            </div>

            <!-- Gráficos -->
            <!-- Gráficos 4 e 5: Bar (Departamentos e Tipos Comparativo) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mt-8">
                
                <!-- Bar Dept -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-800">Departamentos (Comparativo)</h3>
                        <div class="flex gap-2">
                            <button onclick="copyChart('deptBarChart', this)" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Copiar gráfico para a área de transferência">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                Copiar
                            </button>
                            <button onclick="downloadChart('deptBarChart', 'dept_comparativo.png')" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Baixar gráfico com fundo transparente">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                PNG
                            </button>
                        </div>
                    </div>
                    <div class="relative h-80 w-full">
                        <canvas id="deptBarChart"></canvas>
                    </div>
                </div>
                                        
                <!-- Bar Tipo -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-800">Tipos (Comparativo)</h3>
                        <div class="flex gap-2">
                            <button onclick="copyChart('tipoBarChart', this)" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Copiar gráfico para a área de transferência">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                Copiar
                            </button>
                            <button onclick="downloadChart('tipoBarChart', 'tipos_comparativo.png')" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Baixar gráfico com fundo transparente">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                PNG
                            </button>
                        </div>
                    </div>
                    <div class="relative h-80 w-full">
                        <canvas id="tipoBarChart"></canvas>
                    </div>
                </div>
            </div>
                            </br>

            <!-- Gráficos 2 e 3: Pie (Lado a lado) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                
                <!-- Pie Dept -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-800">Chamados por Departamento</h3>
                        <div class="flex gap-2">
                            <button onclick="copyChart('deptChart', this)" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Copiar gráfico para a área de transferência">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                Copiar
                            </button>
                            <button onclick="downloadChart('deptChart', 'departamentos_pizza.png')" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Baixar gráfico com fundo transparente">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                PNG
                            </button>
                        </div>
                    </div>
                    <div class="relative h-80 w-full">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>
                
                <!-- Pie Tipo -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-800">Chamados por Tipo</h3>
                        <div class="flex gap-2">
                            <button onclick="copyChart('tipoChart', this)" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Copiar gráfico para a área de transferência">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                Copiar
                            </button>
                            <button onclick="downloadChart('tipoChart', 'tipos_pizza.png')" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Baixar gráfico com fundo transparente">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                PNG
                            </button>
                        </div>
                    </div>
                    <div class="relative h-80 w-full">
                        <canvas id="tipoChart"></canvas>
                    </div>
                </div>

            </div>
                            </br>
            
              <!-- Gráfico 1: Bar (Ocupando 100% da largura em cima) -->
            <div class="bg-white p-6 rounded-lg shadow mb-8">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-800">Top Solicitantes (Comparativo)</h3>
                    <div class="flex gap-2">
                        <button onclick="copyChart('topUsersChart', this)" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Copiar gráfico para a área de transferência">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                            Copiar
                        </button>
                        <button onclick="downloadChart('topUsersChart', 'top_solicitantes.png')" class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 py-1 px-3 rounded border border-gray-300 shadow-sm flex items-center gap-1 transition-colors" title="Baixar gráfico com fundo transparente">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                            PNG
                        </button>
                    </div>
                </div>
                <div class="relative h-80 w-full">
                    <canvas id="topUsersChart"></canvas>
                </div>
            </div>                       
            <script>
                // Preparar dados do PHP para JS
                const topUsuariosAtual = <?= json_encode($top_usuarios) ?>;
                const topUsuariosAnt = <?= json_encode($top_usuarios_ant) ?>;
                const volDepto = <?= json_encode($vol_depto) ?>;
                const volTipo = <?= json_encode($vol_tipo) ?>;
                const volDeptoAnt = <?= json_encode($vol_depto_ant) ?>;
                const volTipoAnt = <?= json_encode($vol_tipo_ant) ?>;

                // Registrar o plugin DataLabels
                Chart.register(ChartDataLabels);

                // Configuração Global Padrão para Legendas e Gráficos de Barra
                const commonBarOptions = {
                    layout: { padding: { top: 30, bottom: 10 } }, // Mais espaço no topo para os números
                    responsive: true, 
                    maintainAspectRatio: false, 
                    scales: { 
                        y: { 
                            beginAtZero: true, 
                            grace: '30%' // Dá 30% a mais de respiro em cima do maior valor
                        },x: { // <-- ADICIONE ESTE BLOCO PARA A PARTE INFERIOR
                                ticks: {
                                    color: '#d1d7e0', // Aqui você muda a cor dos nomes (Ex: '#000000' é preto)
                                    font: {
                                        weight: 'bold' // Opcional: deixa a fonte em negrito
                                    }
                                }
                            }
                        },
                    plugins: {
                        legend: { 
                            position: 'top',
                            
                            labels: { padding: 20, boxWidth: 20,color: '#d1d7e0' } // Espaço generoso entre os itens da legenda
                        },
                        datalabels: {
                            anchor: 'end',
                            align: 'end',
                            offset: 4, // Distância do número para o topo da barra
                            color: '#d1d7e0',
                            font: { weight: 'bold', size: 12 },
                            formatter: (value) => value > 0 ? value : '' // Não mostra se for 0
                        }
                    }
                };

                const commonPieOptions = {
                    layout: { padding: 20 },
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'top',
                            labels: { padding: 15 ,color: '#d1d7e0'} 
                        },
                        datalabels: {
                            color: '#fff', // Número branco dentro da pizza
                            font: { weight: 'bold', size: 14 },
                            formatter: (value) => value > 0 ? value : ''
                        }
                    }
                };

                // Gráfico 1 - Barras (Top Solicitantes)
                let usersSet = new Set();
                topUsuariosAtual.forEach(u => usersSet.add(u.nome));
                topUsuariosAnt.forEach(u => usersSet.add(u.nome));
                const labelsUsers = Array.from(usersSet).slice(0, 7); // Max 7 para não poluir

                new Chart(document.getElementById('topUsersChart'), {
                    type: 'bar',
                    data: {
                        labels: labelsUsers,
                        datasets: [
                            { label: 'Período Atual', data: labelsUsers.map(nome => (topUsuariosAtual.find(u => u.nome === nome) || {}).total || 0), backgroundColor: '#3b82f6' },
                            { label: 'Período Anterior', data: labelsUsers.map(nome => (topUsuariosAnt.find(u => u.nome === nome) || {}).total || 0), backgroundColor: '#9ca3af' }
                        ]
                    },
                    options: commonBarOptions
                });

                // Gráfico 2 - Pizza (Departamentos)
                new Chart(document.getElementById('deptChart'), {
                    type: 'pie',
                    data: {
                        labels: volDepto.map(d => d.nome),
                        datasets: [{
                            data: volDepto.map(d => d.total),
                            backgroundColor: ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#6366f1']
                        }]
                    },
                    options: commonPieOptions
                });

                // Gráfico 3 - Pizza (Tipos)
                new Chart(document.getElementById('tipoChart'), {
                    type: 'pie',
                    data: {
                        labels: volTipo.map(t => t.nome),
                        datasets: [{
                            data: volTipo.map(t => t.total),
                            backgroundColor: ['#8b5cf6', '#ec4899', '#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#14b8a6', '#6366f1']
                        }]
                    },
                    options: commonPieOptions
                });

                // Gráfico 4 - Barras (Departamentos Comparativo)
                let deptoSet = new Set();
                volDepto.forEach(d => deptoSet.add(d.nome));
                volDeptoAnt.forEach(d => deptoSet.add(d.nome));
                const labelsDepto = Array.from(deptoSet);

                new Chart(document.getElementById('deptBarChart'), {
                    type: 'bar',
                    data: {
                        labels: labelsDepto,
                        datasets: [
                            { label: 'Período Atual', data: labelsDepto.map(nome => (volDepto.find(d => d.nome === nome) || {}).total || 0), backgroundColor: '#10b981' },
                            { label: 'Período Anterior', data: labelsDepto.map(nome => (volDeptoAnt.find(d => d.nome === nome) || {}).total || 0), backgroundColor: '#9ca3af' }
                        ]
                    },
                    options: commonBarOptions
                });

                // Gráfico 5 - Barras (Tipos Comparativo)
                let tipoSet = new Set();
                volTipo.forEach(t => tipoSet.add(t.nome));
                volTipoAnt.forEach(t => tipoSet.add(t.nome));
                const labelsTipoData = Array.from(tipoSet);

                new Chart(document.getElementById('tipoBarChart'), {
                    type: 'bar',
                    data: {
                        labels: labelsTipoData,
                        datasets: [
                            { label: 'Período Atual', data: labelsTipoData.map(nome => (volTipo.find(t => t.nome === nome) || {}).total || 0), backgroundColor: '#8b5cf6' },
                            { label: 'Período Anterior', data: labelsTipoData.map(nome => (volTipoAnt.find(t => t.nome === nome) || {}).total || 0), backgroundColor: '#9ca3af' }
                        ]
                    },
                    options: commonBarOptions
                });

                // ==========================================
                // FUNÇÕES DE EXPORTAÇÃO E CÓPIA DE GRÁFICOS
                // ==========================================

                function downloadChart(canvasId, filename) {
                    const canvas = document.getElementById(canvasId);
                    const imageUrl = canvas.toDataURL('image/png');
                    const link = document.createElement('a');
                    link.href = imageUrl;
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }

                async function copyChart(canvasId, btnElement) {
                    const originalHTML = btnElement.innerHTML;
                    const canvas = document.getElementById(canvasId);
                    
                    try {
                        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
                        const item = new ClipboardItem({ 'image/png': blob });
                        await navigator.clipboard.write([item]);
                        
                        // Feedback de Sucesso
                        btnElement.innerHTML = '✓ Copiado';
                        btnElement.classList.add('bg-green-100', 'text-green-700', 'border-green-300');
                        setTimeout(() => {
                            btnElement.innerHTML = originalHTML;
                            btnElement.classList.remove('bg-green-100', 'text-green-700', 'border-green-300');
                        }, 2000);

                    } catch (err) {
                        console.error('Erro ao copiar gráfico', err);
                        // Fallback: Se o navegador travar o Clipboard API, abre a tela pro usuário copiar manualmente
                        mostrarModalCopia(canvas.toDataURL('image/png'));
                    }
                }

                function mostrarModalCopia(dataUrl) {
                    const modalAntigo = document.getElementById('modalCopiaFallback');
                    if (modalAntigo) modalAntigo.remove();

                    const modalHtml = `
                        <div id="modalCopiaFallback" class="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center z-50 p-4">
                            <div class="bg-white rounded-lg p-6 max-w-2xl w-full text-center shadow-xl">
                                <h3 class="text-xl font-bold mb-2 text-gray-800">Copiar Gráfico</h3>
                                <p class="text-gray-600 mb-4 text-sm">O navegador bloqueou a cópia invisível. <br><strong>Clique com o botão direito</strong> na imagem abaixo e selecione <strong>"Copiar imagem"</strong>.</p>
                                <!-- O Fundo xadrez abaixo ajuda a visualizar que a imagem copiada será transparente -->
                                <div class="border rounded bg-gray-50 p-4 mb-4 flex justify-center bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyMCIgaGVpZ2h0PSIyMCI+CjxyZWN0IHdpZHRoPSIyMCIgaGVpZ2h0PSIyMCIgZmlsbD0iI2ZmZiI+PC9yZWN0Pgo8cmVjdCB3aWR0aD0iMTAiIGhlaWdodD0iMTAiIGZpbGw9IiNlZWVlZWUiPjwvcmVjdD4KPHJlY3QgeD0iMTAiIHk9IjEwIiB3aWR0aD0iMTAiIGhlaWdodD0iMTAiIGZpbGw9IiNlZWVlZWUiPjwvcmVjdD4KPC9zdmc+')]">
                                    <img src="${dataUrl}" class="max-h-80 shadow border bg-transparent cursor-pointer" alt="Gráfico para copiar" title="Clique com o botão direito e selecione Copiar imagem">
                                </div>
                                <button onclick="document.getElementById('modalCopiaFallback').remove()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded">
                                    Fechar
                                </button>
                            </div>
                        </div>
                    `;
                    document.body.insertAdjacentHTML('beforeend', modalHtml);
                }
            </script>

        <?php
        // ==========================================
        // PÁGINA: FILA DE CHAMADOS
        // ==========================================
        elseif ($page == 'chamados'): 
            $stmt = $pdo->query("SELECT p.id, p.descricao_problema, p.data_abertura, p.status, d.nome as departamento, t.nome as tipo, u.nome as usuario 
                                 FROM problema_solucao p 
                                 JOIN departamento d ON p.departamento_id = d.id
                                 JOIN tipos t ON p.tipo_id = t.id
                                 JOIN usuarios u ON p.usuario_id = u.id
                                 ORDER BY CASE WHEN p.status = 'Aberto' THEN 1 ELSE 2 END, p.data_abertura DESC LIMIT 50");
            $chamados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">Últimos 50 Chamados</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuário</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Problema</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data Abertura</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach($chamados as $c): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">#<?= $c['id'] ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $c['status'] == 'Aberto' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
                                        <?= $c['status'] ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($c['usuario']) ?><br>
                                    <span class="text-xs text-gray-500"><?= htmlspecialchars($c['departamento']) ?></span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                                    <span class="font-bold"><?= htmlspecialchars($c['tipo']) ?>:</span> <?= htmlspecialchars($c['descricao_problema']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('d/m/Y H:i', strtotime($c['data_abertura'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <?php if($c['status'] == 'Aberto'): ?>
                                        <button onclick="abrirModalResolucao(<?= $c['id'] ?>, this.getAttribute('data-descricao'))" data-descricao="<?= htmlspecialchars($c['descricao_problema']) ?>" class="text-indigo-600 hover:text-indigo-900 font-bold">Resolver</button>
                                    <?php else: ?>
                                        <span class="text-gray-400">Resolvido</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Resolução -->
            <div id="modalResolucao" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center">
                <div class="bg-white p-8 rounded shadow-lg w-full max-w-md">
                    <h2 class="text-xl font-bold mb-4">Resolver Chamado #<span id="modalChamadoIdText"></span></h2>
                    
                    <div class="mb-4 bg-gray-50 p-3 rounded border border-gray-200">
                        <p class="text-sm font-bold text-gray-700 mb-1">Problema Relatado:</p>
                        <p id="modalChamadoDescricao" class="text-sm text-gray-600 italic"></p>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="resolver_chamado">
                        <input type="hidden" name="chamado_id" id="modalChamadoIdInput">
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-bold mb-2">Descrição da Solução</label>
                            <textarea name="solucao" required rows="4" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"></textarea>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" onclick="fecharModal()" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-4 rounded">Cancelar</button>
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">Salvar Resolução</button>
                        </div>
                    </form>
                </div>
            </div>
            <script>
                function abrirModalResolucao(id, descricao) {
                    document.getElementById('modalChamadoIdText').innerText = id;
                    document.getElementById('modalChamadoIdInput').value = id;
                    document.getElementById('modalChamadoDescricao').innerText = descricao;
                    document.getElementById('modalResolucao').classList.remove('hidden');
                }
                function fecharModal() {
                    document.getElementById('modalResolucao').classList.add('hidden');
                }
            </script>

        <?php
        // ==========================================
        // PÁGINA: PESQUISA (BUSCA DE PROBLEMAS E SOLUÇÕES)
        // ==========================================
        elseif ($page == 'pesquisa'): 
            $resultados = [];
            $buscou = false;
            
            if (isset($_GET['btn_buscar'])) {
                $buscou = true;
                $where = "1=1";
                $params = [];
                
                if (!empty($_GET['termo'])) {
                    // Busca a palavra-chave tanto no problema quanto na solução
                    $where .= " AND (p.descricao_problema LIKE ? OR p.descricao_solucao LIKE ?)";
                    $params[] = '%' . $_GET['termo'] . '%';
                    $params[] = '%' . $_GET['termo'] . '%';
                }
                if (!empty($_GET['data_inicio'])) { $where .= " AND p.data_abertura >= ?"; $params[] = $_GET['data_inicio'] . ' 00:00:00'; }
                if (!empty($_GET['data_fim'])) { $where .= " AND p.data_abertura <= ?"; $params[] = $_GET['data_fim'] . ' 23:59:59'; }
                if (!empty($_GET['departamento'])) { $where .= " AND p.departamento_id = ?"; $params[] = $_GET['departamento']; }
                if (!empty($_GET['tipo'])) { $where .= " AND p.tipo_id = ?"; $params[] = $_GET['tipo']; }
                if (!empty($_GET['usuario'])) { $where .= " AND p.usuario_id = ?"; $params[] = $_GET['usuario']; }

                $sql = "SELECT p.id, p.status, p.descricao_problema, p.descricao_solucao, p.data_abertura, p.data_resolucao, 
                               t.nome as tipo, d.nome as departamento, u.nome as usuario 
                        FROM problema_solucao p 
                        JOIN departamento d ON p.departamento_id = d.id 
                        JOIN tipos t ON p.tipo_id = t.id 
                        JOIN usuarios u ON p.usuario_id = u.id 
                        WHERE $where 
                        ORDER BY p.data_abertura DESC LIMIT 100";
                        
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        ?>
            <!-- Formulário de Pesquisa -->
            <div class="bg-white p-6 rounded-lg shadow-md mb-8">
                <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Pesquisar Base de Conhecimento</h3>
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <input type="hidden" name="page" value="pesquisa">
                    
                    <div class="w-full md:w-1/3">
                        <label class="block text-sm font-medium text-gray-700">Palavra-chave (Problema ou Solução)</label>
                        <input type="text" name="termo" value="<?= htmlspecialchars($_GET['termo'] ?? '') ?>" placeholder="Ex: erro impressora, tela azul..." class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border focus:border-blue-500 focus:ring focus:ring-blue-200">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Data Inicial</label>
                        <input type="date" name="data_inicio" value="<?= htmlspecialchars($_GET['data_inicio'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Data Final</label>
                        <input type="date" name="data_fim" value="<?= htmlspecialchars($_GET['data_fim'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Departamento</label>
                        <select name="departamento" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                            <option value="">Todos</option>
                            <?php foreach($departamentos as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (isset($_GET['departamento']) && $_GET['departamento'] == $d['id']) ? 'selected' : '' ?>><?= $d['nome'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Tipo</label>
                        <select name="tipo" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                            <option value="">Todos</option>
                            <?php foreach($tipos as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= (isset($_GET['tipo']) && $_GET['tipo'] == $t['id']) ? 'selected' : '' ?>><?= $t['nome'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Usuário</label>
                        <select name="usuario" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border">
                            <option value="">Todos</option>
                            <?php foreach($usuarios as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= (isset($_GET['usuario']) && $_GET['usuario'] == $u['id']) ? 'selected' : '' ?>><?= $u['nome'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex-grow flex justify-end md:justify-start">
                        <button type="submit" name="btn_buscar" value="1" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                              <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                            </svg>
                            Buscar
                        </button>
                    </div>
                </form>
            </div>
            
            <?php if ($buscou): ?>
                <div class="mb-4 text-gray-600 font-medium">
                    <?= count($resultados) ?> resultado(s) encontrado(s).
                </div>
                
                <div class="space-y-6">
                    <?php foreach($resultados as $r): ?>
                        <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                            <div class="bg-gray-50 px-6 py-3 border-b border-gray-200 flex flex-wrap justify-between items-center gap-2">
                                <div class="flex items-center gap-4">
                                    <span class="font-bold text-gray-700">Chamado #<?= $r['id'] ?></span>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $r['status'] == 'Aberto' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
                                        <?= $r['status'] ?>
                                    </span>
                                </div>
                                <div class="text-sm text-gray-500 flex gap-4">
                                    <span><strong>Abertura:</strong> <?= date('d/m/Y H:i', strtotime($r['data_abertura'])) ?></span>
                                    <?php if ($r['data_resolucao']): ?>
                                        <span><strong>Resolução:</strong> <?= date('d/m/Y H:i', strtotime($r['data_resolucao'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="px-6 py-4 flex flex-col md:flex-row gap-4">
                                <div class="md:w-1/4 space-y-2 border-r border-gray-100 pr-4">
                                    <div><p class="text-xs text-gray-500 uppercase font-bold">Solicitante</p><p class="font-medium text-gray-800"><?= htmlspecialchars($r['usuario']) ?></p></div>
                                    <div><p class="text-xs text-gray-500 uppercase font-bold">Departamento</p><p class="text-gray-700"><?= htmlspecialchars($r['departamento']) ?></p></div>
                                    <div><p class="text-xs text-gray-500 uppercase font-bold">Tipo</p><p class="text-gray-700"><?= htmlspecialchars($r['tipo']) ?></p></div>
                                </div>
                                <div class="md:w-3/4 space-y-4">
                                    <div>
                                        <h4 class="text-sm font-bold text-red-600 uppercase mb-1 flex items-center gap-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                            Problema Relatado
                                        </h4>
                                        <p class="text-gray-800 bg-red-50 p-3 rounded border border-red-100 whitespace-pre-wrap"><?= htmlspecialchars($r['descricao_problema']) ?></p>
                                    </div>
                                    
                                    <?php if ($r['descricao_solucao']): ?>
                                    <div>
                                        <h4 class="text-sm font-bold text-green-600 uppercase mb-1 flex items-center gap-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            Solução Aplicada
                                        </h4>
                                        <p class="text-gray-800 bg-green-50 p-3 rounded border border-green-100 whitespace-pre-wrap"><?= htmlspecialchars($r['descricao_solucao']) ?></p>
                                    </div>
                                    <?php else: ?>
                                    <div class="bg-gray-50 p-3 rounded border border-gray-200 text-gray-500 italic text-sm">
                                        Chamado ainda não resolvido. Nenhuma solução registrada.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($resultados)): ?>
                        <div class="bg-white p-8 text-center rounded-lg shadow border border-gray-200">
                            <p class="text-gray-500 text-lg">Nenhum chamado encontrado com estes filtros.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php
        // ==========================================
        // PÁGINA: NOVO CHAMADO
        // ==========================================
        elseif ($page == 'novo_chamado'): 
        ?>
            <div class="max-w-2xl mx-auto bg-white p-8 rounded-lg shadow-md">
                <h2 class="text-2xl font-bold mb-6 text-gray-800 border-b pb-2">Abertura de Novo Chamado</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="novo_chamado">
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2">Solicitante (Usuário)</label>
                        <select name="usuario_id" required class="shadow border rounded w-full py-2 px-3 text-gray-700 bg-white">
                            <option value="">Selecione...</option>
                            <?php foreach($usuarios as $u): ?><option value="<?= $u['id'] ?>"><?= $u['nome'] ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2">Departamento</label>
                        <select name="departamento_id" required class="shadow border rounded w-full py-2 px-3 text-gray-700 bg-white">
                            <option value="">Selecione...</option>
                            <?php foreach($departamentos as $d): ?><option value="<?= $d['id'] ?>"><?= $d['nome'] ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2">Tipo de Problema</label>
                        <select name="tipo_id" required class="shadow border rounded w-full py-2 px-3 text-gray-700 bg-white">
                            <option value="">Selecione...</option>
                            <?php foreach($tipos as $t): ?><option value="<?= $t['id'] ?>"><?= $t['nome'] ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2">Descrição Detalhada do Problema</label>
                        <textarea name="descricao" required rows="5" class="shadow border rounded w-full py-2 px-3 text-gray-700"></textarea>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow">
                            Registrar Chamado
                        </button>
                    </div>
                </form>
            </div>

        <?php
        // ==========================================
        // PÁGINA: CADASTROS BÁSICOS
        // ==========================================
        elseif ($page == 'cadastros'): 
        ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Cadastro Usuario -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <h3 class="text-lg font-bold mb-4 border-b pb-2">Novo Usuário</h3>
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="action" value="cadastrar_basico">
                        <input type="hidden" name="tabela" value="usuarios">
                        <input type="text" name="nome" required placeholder="Nome do Usuário" class="w-full border p-2 rounded mb-2">
                        <button type="submit" class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600">Adicionar</button>
                    </form>
                    <ul class="text-sm text-gray-600 max-h-40 overflow-y-auto pr-2">
                        <?php foreach($usuarios as $u): ?>
                        <li class="border-b py-2 flex justify-between items-center">
                            <span><?= htmlspecialchars($u['nome']) ?></span>
                            <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir?');">
                                <input type="hidden" name="action" value="excluir_basico">
                                <input type="hidden" name="tabela" value="usuarios">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700 font-bold text-xs bg-red-100 hover:bg-red-200 px-2 py-1 rounded" title="Excluir">X</button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Cadastro Departamento -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <h3 class="text-lg font-bold mb-4 border-b pb-2">Novo Departamento</h3>
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="action" value="cadastrar_basico">
                        <input type="hidden" name="tabela" value="departamento">
                        <input type="text" name="nome" required placeholder="Nome do Setor" class="w-full border p-2 rounded mb-2">
                        <button type="submit" class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600">Adicionar</button>
                    </form>
                    <ul class="text-sm text-gray-600 max-h-40 overflow-y-auto pr-2">
                        <?php foreach($departamentos as $d): ?>
                        <li class="border-b py-2 flex justify-between items-center">
                            <span><?= htmlspecialchars($d['nome']) ?></span>
                            <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir?');">
                                <input type="hidden" name="action" value="excluir_basico">
                                <input type="hidden" name="tabela" value="departamento">
                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700 font-bold text-xs bg-red-100 hover:bg-red-200 px-2 py-1 rounded" title="Excluir">X</button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Cadastro Tipos -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <h3 class="text-lg font-bold mb-4 border-b pb-2">Novo Tipo de Chamado</h3>
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="action" value="cadastrar_basico">
                        <input type="hidden" name="tabela" value="tipos">
                        <input type="text" name="nome" required placeholder="Ex: Manutenção" class="w-full border p-2 rounded mb-2">
                        <button type="submit" class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600">Adicionar</button>
                    </form>
                    <ul class="text-sm text-gray-600 max-h-40 overflow-y-auto pr-2">
                        <?php foreach($tipos as $t): ?>
                        <li class="border-b py-2 flex justify-between items-center">
                            <span><?= htmlspecialchars($t['nome']) ?></span>
                            <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir?');">
                                <input type="hidden" name="action" value="excluir_basico">
                                <input type="hidden" name="tabela" value="tipos">
                                     <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700 font-bold text-xs bg-red-100 hover:bg-red-200 px-2 py-1 rounded" title="Excluir">X</button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>