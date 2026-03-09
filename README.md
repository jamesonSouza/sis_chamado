# 🎫 HelpDesk Plus - Sistema de Chamados

Um sistema de chamados (HelpDesk) simples e responsivo desenvolvido em PHP e MySQL. Ele permite a abertura, gerenciamento e resolução de chamados de TI/Suporte, além de contar com um dashboard analítico completo.

## ✨ Funcionalidades

- **📊 Análise & Dashboard:** Visão geral de chamados com gráficos interativos (Chart.js), comparativos de períodos e exportação de gráficos (PNG/Copiar).
- **📋 Fila de Chamados:** Listagem de chamados abertos e resolvidos com opção de registrar a solução do problema.
- **🔍 Base de Conhecimento:** Busca avançada por chamados antigos (problemas e soluções) usando filtros de data, tipo, usuário ou departamento.
- **⚙️ Cadastros Básicos:** Gerenciamento de Usuários, Departamentos e Tipos de Chamados.
- **📥 Exportação:** Geração de relatórios em CSV (Excel) com os dados filtrados.
- **🛠️ Auto-Setup de Banco de Dados:** O sistema cria o banco e as tabelas automaticamente na primeira execução.

## 🚀 Tecnologias Utilizadas

- **Back-end:** PHP (com PDO)
- **Banco de Dados:** MySQL
- **Front-end:** HTML, Tailwind CSS (via CDN)
- **Gráficos:** Chart.js & ChartDataLabels

## 📁 Estrutura do Projeto

- `index.php`: Arquivo principal contendo a interface, roteamento e lógica da aplicação.
- `conexao.php`: Arquivo dedicado para a configuração e conexão com o banco de dados MySQL.

## ⚙️ Como Executar o Projeto

1. Certifique-se de ter um servidor local instalado (como **XAMPP**, **WampServer** ou **Laragon**).
2. Clone este repositório na pasta pública do seu servidor (ex: `htdocs` no XAMPP ou `www` no WampServer).
3. Abra o arquivo `conexao.php` e configure as credenciais do seu MySQL, se necessário:
   ```php
   $db_host = 'localhost';
   $db_user = 'root'; // Seu usuário do banco
   $db_pass = '';     // Sua senha do banco
