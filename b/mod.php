<?php
declare(strict_types=1);
// =========================
// MOD.PHP - SISTEMA DE MODERAÇÃO COM FILTRO DE PALAVRAS E CONFIGURAÇÕES
// =========================
date_default_timezone_set('America/Sao_Paulo');

// Configuração - DEVE CORRESPONDER AO SEU INDEX.PHP
const POSTS_PER_PAGE = 10;
const REPLIES_PER_PAGE = 1000;
const MAX_UPLOAD_SIZE = 15 * 1024 * 1024; // 15MB
const MAX_PREVIEW_CHARS = 500;
const MAIN_REPLIES_SHOWN = 5;
const MAX_REPLY_PREVIEW_CHARS = 500;
const ALLOWED_FILE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/webm', 'video/mp4'];
const BOARD_TITLE = '/b/ - Aleatório';
const BOARD_SUBTITLE = '';
const ADMIN_PASSWORD = 'admin'; // Mude para sua senha
const MANAGE_COOKIE = 'messageboard_manage';
const BOARD_NAME = 'b';
const MOD_ADMIN_NAME = 'admin'; // Nome padrão do admin

// Constantes de processamento de imagem
const IMAGE_QUALITY = 85;
const THUMB_MAX_WIDTH = 250;
const THUMB_MAX_HEIGHT = 250;
const USE_WEBP = true;
const GENERATE_THUMBNAILS = true;

// Configuração de logging de erros
ini_set('log_errors', '1');
ini_set('error_log', 'logs/php_errors.log');

// Iniciar buffer de saída para evitar problemas de cabeçalho
ob_start();

// Iniciar sessão ANTES de qualquer saída
session_start([
    'cookie_lifetime' => 86400,
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'cookie_samesite' => 'Strict',
]);

// Regenerar ID da sessão para evitar fixação de sessão
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

/* =========================
   FILTRO DE PALAVRAS - CONFIGURAÇÃO
========================= */

// Carregar lista de palavras bloqueadas
function loadWordFilters(): array {
    $filtersFile = __DIR__ . '/word_filters.json';
    if (file_exists($filtersFile)) {
        $content = file_get_contents($filtersFile);
        $data = json_decode($content, true);
        if (is_array($data)) {
            return $data;
        }
    }
    // Retornar estrutura padrão se arquivo não existir
    return [
        'exact' => [], // Palavras exatas (case insensitive)
        'contains' => [] // Palavras que contém (case insensitive)
    ];
}

// Salvar lista de palavras bloqueadas
function saveWordFilters(array $filters): bool {
    $filtersFile = __DIR__ . '/word_filters.json';
    return file_put_contents($filtersFile, json_encode($filters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// Verificar se um texto contém palavras bloqueadas
function checkWordFilters(string $text, array $filters): ?string {
    $textLower = mb_strtolower($text, 'UTF-8');
    
    // Verificar palavras exatas
    foreach ($filters['exact'] as $word) {
        $wordLower = mb_strtolower($word, 'UTF-8');
        // Dividir o texto em palavras
        $words = preg_split('/\s+/', $textLower);
        foreach ($words as $w) {
            // Remover pontuação no final
            $w = preg_replace('/[^\p{L}\p{N}]+$/u', '', $w);
            if ($w === $wordLower) {
                return $word;
            }
        }
    }
    
    // Verificar palavras que contém
    foreach ($filters['contains'] as $word) {
        $wordLower = mb_strtolower($word, 'UTF-8');
        if (strpos($textLower, $wordLower) !== false) {
            return $word;
        }
    }
    
    return null;
}

// Adicionar palavra ao filtro
function addWordFilter(string $word, string $type): array {
    $filters = loadWordFilters();
    
    // Normalizar palavra
    $word = trim($word);
    if (empty($word)) {
        return ['success' => false, 'message' => 'Palavra não pode estar vazia'];
    }
    
    // Verificar se já existe
    if (in_array($word, $filters[$type])) {
        return ['success' => false, 'message' => 'Palavra já existe no filtro'];
    }
    
    $filters[$type][] = $word;
    if (saveWordFilters($filters)) {
        return ['success' => true, 'message' => 'Palavra adicionada com sucesso'];
    }
    
    return ['success' => false, 'message' => 'Erro ao salvar filtro'];
}

// Remover palavra do filtro
function removeWordFilter(string $word, string $type): array {
    $filters = loadWordFilters();
    
    $key = array_search($word, $filters[$type]);
    if ($key !== false) {
        unset($filters[$type][$key]);
        // Reindexar array
        $filters[$type] = array_values($filters[$type]);
        
        if (saveWordFilters($filters)) {
            return ['success' => true, 'message' => 'Palavra removida com sucesso'];
        }
    }
    
    return ['success' => false, 'message' => 'Palavra não encontrada'];
}

/* =========================
   FUNÇÕES DE SEGURANÇA
========================= */

/**
 * Sanitiza inteiros com limites
 */
function sanitizeInt($value, $min = 1, $max = PHP_INT_MAX): int {
    $int = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max]
    ]);
    return $int !== false ? $int : $min;
}

/**
 * Sanitiza strings para SQL
 */
function sanitizeString(string $value, int $maxLength = 1000, bool $escapeHtml = true): string {
    $value = trim($value);
    $value = substr($value, 0, $maxLength);
    
    if ($escapeHtml) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    
    return $value;
}

function handleError(string $msg, int $code = 400): never {
    http_response_code($code);
    $_SESSION['error'] = $msg;
    if (ob_get_level() > 0) ob_end_clean();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// =========================
// INICIALIZAÇÃO DO SISTEMA
// =========================

// CORREÇÃO: Diretórios específicos para notícias
$uploadsDir = 'uploads/';
$thumbsDir = 'uploads/thumbs/';
$bannersDir = 'banners/';
$logsDir = 'logs/';
$newsDir = '../news/'; // Diretório das páginas HTML das notícias
$newsUploadsDir = '../news/uploads/'; // Diretório para uploads de notícias
$newsThumbsDir = '../news/uploads/thumbs/'; // Diretório para thumbnails de notícias

// Criar diretórios se não existirem
foreach ([$uploadsDir, $thumbsDir, $bannersDir, $logsDir, $newsDir, $newsUploadsDir, $newsThumbsDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!is_writable($dir)) {
        die("Diretório não gravável: $dir");
    }
}

try {
    $db = new PDO('sqlite:' . $uploadsDir . 'message_board.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec("PRAGMA timezone = '-03:00'"); // São Paulo UTC-3
} catch (PDOException $e) {
    error_log('Erro DB: ' . $e->getMessage(), 3, $logsDir . 'db_errors.log');
    die('Falha na conexão com o banco de dados.');
}

// =========================
// AUTENTICAÇÃO
// =========================
$canmanage = false;
$hashedcookie = $_COOKIE[MANAGE_COOKIE] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['managepassword'])) {
    if ($_POST['managepassword'] === ADMIN_PASSWORD) {
        setcookie(MANAGE_COOKIE, sha1(ADMIN_PASSWORD), time() + (86400 * 30), "/"); // 30 dias
        $canmanage = true;
    } else {
        $login_error = 'Senha incorreta.';
    }
} elseif ($hashedcookie === sha1(ADMIN_PASSWORD)) {
    $canmanage = true;
}

if (!$canmanage) {
    // Limpar buffer de saída antes de enviar cabeçalhos
    ob_end_clean();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo BOARD_TITLE . ' - Moderação'; ?></title>
    <style>
        body {
            background-color: #eef2ff;
            font-family: arial,helvetica,sans-serif;
            font-size: 10pt;
            margin: 0;
            padding: 20px;
        }
        .login-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 20px;
            background-color: #f0e0d6;
            border: 1px solid #d9bfb7;
            border-radius: 5px;
            text-align: center;
        }
        h1 {
            color: #af0a0f;
            font-size: 24px;
            margin-bottom: 20px;
        }
        input[type="password"], input[type="submit"] {
            padding: 8px;
            margin: 5px;
            font-size: 14px;
        }
        input[type="password"] {
            width: 200px;
        }
        input[type="submit"] {
            background-color: #34345c;
            color: white;
            border: none;
            cursor: pointer;
            padding: 8px 15px;
        }
        input[type="submit"]:hover {
            background-color: #af0a0f;
        }
        .error {
            color: #ff0000;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1><?php echo BOARD_TITLE; ?> - Moderação</h1>
        <form method="POST" action="mod.php">
            <input type="password" name="managepassword" placeholder="Senha de moderação" required>
            <input type="submit" value="Login">
        </form>
        <?php if (isset($login_error)) echo '<div class="error">' . $login_error . '</div>'; ?>
    </div>
</body>
</html>
    <?php
    exit;
}

// =========================
// TOKEN CSRF
// =========================
$csrf_token = generateCsrfToken();

/* =========================
   CONFIGURAÇÕES DO SISTEMA
========================= */

// Criar tabela de configurações se não existir
$db->exec("
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at DATETIME DEFAULT (datetime('now', 'localtime'))
)");

// Inserir configurações padrão se não existirem
$checkSettings = $db->query("SELECT COUNT(*) FROM settings WHERE key = 'approval_system'")->fetchColumn();
if ($checkSettings == 0) {
    $db->exec("INSERT INTO settings (key, value) VALUES ('approval_system', '0')"); // 0 = desativado por padrão
}

$checkReadOnly = $db->query("SELECT COUNT(*) FROM settings WHERE key = 'readonly_mode'")->fetchColumn();
if ($checkReadOnly == 0) {
    $db->exec("INSERT INTO settings (key, value) VALUES ('readonly_mode', '0')"); // 0 = desativado por padrão
}

// Função para obter configuração
function getSetting($db, $key, $default = null) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['value'] : $default;
}

// Função para salvar configuração
function saveSetting($db, $key, $value) {
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now', 'localtime'))");
    return $stmt->execute([$key, $value]);
}

// Processar alteração de configuração
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        handleError('Token CSRF inválido', 403);
    }
    
    $approval_system = isset($_POST['approval_system']) && $_POST['approval_system'] === '1' ? '1' : '0';
    $readonly_mode = isset($_POST['readonly_mode']) && $_POST['readonly_mode'] === '1' ? '1' : '0';
    
    saveSetting($db, 'approval_system', $approval_system);
    saveSetting($db, 'readonly_mode', $readonly_mode);
    
    $_SESSION['success'] = 'Configurações salvas com sucesso!';
    
    ob_end_clean();
    header('Location: mod.php?section=settings');
    exit;
}

// Obter configurações atuais
$approval_system = getSetting($db, 'approval_system', '0');
$readonly_mode = getSetting($db, 'readonly_mode', '0');

/* =========================
   FUNÇÕES DE MENÇÕES
========================= */

// Função para extrair menções de um texto
function extractMentions($text) {
    $mentions = [];
    preg_match_all('/>>(\d+)/', $text, $matches);
    if (!empty($matches[1])) {
        $mentions = array_unique($matches[1]); // Remove duplicatas
    }
    return $mentions;
}

// Função para registrar menções no banco de dados
function registerMentions($db, $source_id, $text) {
    $mentions = extractMentions($text);
    
    foreach ($mentions as $target_id) {
        // Verificar se o post alvo existe
        $check = $db->prepare("SELECT id FROM posts WHERE id = ? AND deleted = 0");
        $check->execute([$target_id]);
        if ($check->fetch()) {
            // Registrar a menção
            $stmt = $db->prepare("INSERT INTO mentions (source_id, target_id) VALUES (?, ?)");
            $stmt->execute([$source_id, $target_id]);
        }
    }
}

// Função para obter menções de um post (retorna os IDs dos posts que mencionaram)
function getPostMentions($db, $post_id) {
    $stmt = $db->prepare("
        SELECT m.source_id 
        FROM mentions m
        WHERE m.target_id = ?
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$post_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Função para obter informações de um post (para redirecionamento)
function getPostInfo($db, $post_id) {
    $stmt = $db->prepare("SELECT id, is_reply, parent_id FROM posts WHERE id = ? AND deleted = 0");
    $stmt->execute([$post_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Função para formatar texto com links de menção
function formatPostTextWithMentions($text, $db = null) {
    $lines = explode("\n", $text);
    $formattedLines = [];
    
    foreach ($lines as $line) {
        $trimmedLine = rtrim($line);
        
        if ($trimmedLine === '') {
            $formattedLines[] = '';
            continue;
        }
        
        if (preg_match('/^>.*/', $trimmedLine)) {
            $formattedLines[] = '<span class="greentext">' . htmlspecialchars($trimmedLine) . '</span>';
            continue;
        }
        
        $escapedLine = htmlspecialchars($trimmedLine);
        
        $escapedLine = preg_replace_callback('/>>(\d+)/', function($matches) use ($db) {
            $target_id = $matches[1];
            return '<a href="#post-' . $target_id . '" class="mention-link" onclick="highlightPost(' . $target_id . '); return false;">>>' . $target_id . '</a>';
        }, $escapedLine);
        
        $processedLine = preg_replace('/==([^=\n]+?)==/u', '<span class="redtext">$1</span>', $escapedLine);
        $processedLine = preg_replace('/\*\*([^\*\n]+?)\*\*/u', '<span class="spoiler-text">$1</span>', $processedLine);
        
        $formattedLines[] = $processedLine;
    }
    
    return implode("<br>", $formattedLines);
}

/* =========================
   PROCESSAR AÇÕES DO FILTRO DE PALAVRAS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_word_filter'])) {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            handleError('Token CSRF inválido', 403);
        }
        
        $word = trim($_POST['word'] ?? '');
        $type = $_POST['filter_type'] ?? 'exact';
        
        if (!in_array($type, ['exact', 'contains'])) {
            $type = 'exact';
        }
        
        $result = addWordFilter($word, $type);
        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        
        ob_end_clean();
        header('Location: mod.php?section=filters');
        exit;
    }
    
    if (isset($_POST['remove_word_filter'])) {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            handleError('Token CSRF inválido', 403);
        }
        
        $word = $_POST['word'] ?? '';
        $type = $_POST['filter_type'] ?? 'exact';
        
        if (!in_array($type, ['exact', 'contains'])) {
            $type = 'exact';
        }
        
        $result = removeWordFilter($word, $type);
        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        
        ob_end_clean();
        header('Location: mod.php?section=filters');
        exit;
    }
}

/* =========================
   LIDAR COM NOTÍCIAS (COM DIRETÓRIO CORRIGIDO)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['news_submit'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        handleError('Token CSRF inválido', 403);
    }
    
    $title = trim($_POST['news_title'] ?? '');
    $content = trim($_POST['news_content'] ?? '');
    $author = trim($_POST['news_author'] ?? 'Admin');
    
    // Sanitizar inputs - NÃO escapar HTML para título e conteúdo
    $title = sanitizeString($title, 200, false); // Não escapar HTML para título
    $content = sanitizeString($content, 50000, false); // Não escapar HTML para conteúdo
    $author = sanitizeString($author, 50);
    
    if (empty($title)) {
        handleError('Título da notícia é obrigatório');
    }
    
    if (empty($content)) {
        handleError('Conteúdo da notícia é obrigatório');
    }
    
    if (empty($author)) {
        $author = 'Admin';
    }
    
    // Processar upload de arquivo - CORREÇÃO: Usando diretório de notícias
    $mediaPath = null;
    $thumbPath = null;
    $hasFile = !empty($_FILES['news_file']['tmp_name']) && $_FILES['news_file']['size'] > 0;
    
    if ($hasFile) {
        $tmp = $_FILES['news_file']['tmp_name'];
        $type = mime_content_type($tmp);

        if (!in_array($type, ALLOWED_FILE_TYPES, true)) {
            handleError('Tipo de arquivo inválido: ' . $type);
        }
        
        if ($_FILES['news_file']['size'] > MAX_UPLOAD_SIZE) {
            handleError('Arquivo muito grande. Tamanho máximo: ' . round(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB');
        }

        if (str_starts_with($type, 'image/')) {
            // CORREÇÃO: Usa função específica para notícias
            $result = processAndOptimizeNewsImage($tmp, $_FILES['news_file']['name']);
            $mediaPath = $result['main'];
            $thumbPath = $result['thumb'];
        } elseif (str_starts_with($type, 'video/')) {
            // CORREÇÃO: Usa função específica para notícias
            $result = processNewsVideo($tmp, $_FILES['news_file']['name']);
            $mediaPath = $result['main'];
            $thumbPath = $result['thumb'];
        }
    }
    
    // Inserir notícia no banco de dados
    try {
        $stmt = $db->prepare("INSERT INTO news (title, content, author, media, thumb) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $content, $author, $mediaPath, $thumbPath]);
        
        // Gerar página HTML única das notícias
        generateNewsPages();
        
        $_SESSION['success'] = 'Notícia publicada com sucesso!';
    } catch (PDOException $e) {
        error_log('Erro ao inserir notícia: ' . $e->getMessage());
        handleError('Erro ao salvar notícia no banco de dados: ' . $e->getMessage());
    }
    
    // Limpar buffer antes de redirecionar
    ob_end_clean();
    header('Location: mod.php?section=news');
    exit;
}

// Ação para excluir notícia
if (isset($_GET['action']) && $_GET['action'] === 'delete_news' && isset($_GET['news_id'])) {
    $news_id = sanitizeInt($_GET['news_id']);
    
    // Verificar CSRF
    if (!isset($_GET['csrf']) || !validateCsrfToken($_GET['csrf'])) {
        handleError('Token CSRF inválido para esta ação', 403);
    }
    
    // Obter informações da notícia para excluir arquivos
    $stmt = $db->prepare('SELECT media, thumb FROM news WHERE id = ?');
    $stmt->execute([$news_id]);
    $news = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($news) {
        // Excluir arquivos de mídia do diretório de notícias
        if (!empty($news['media'])) {
            deleteFileIfExists($news['media']);
        }
        if (!empty($news['thumb'])) {
            deleteFileIfExists($news['thumb']);
        }
        
        // Excluir notícia do banco de dados
        $stmt = $db->prepare('DELETE FROM news WHERE id = ?');
        $stmt->execute([$news_id]);
        
        // Regenerar página HTML única
        generateNewsPages();
        
        $_SESSION['success'] = 'Notícia excluída com sucesso!';
    }
    
    // Limpar buffer antes de redirecionar
    ob_end_clean();
    header('Location: mod.php?section=news');
    exit;
}

// Ação para editar notícia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_news_submit'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        handleError('Token CSRF inválido', 403);
    }
    
    $news_id = sanitizeInt($_POST['news_id']);
    $title = trim($_POST['edit_news_title'] ?? '');
    $content = trim($_POST['edit_news_content'] ?? '');
    $author = trim($_POST['edit_news_author'] ?? 'Admin');
    
    // Sanitizar inputs - NÃO escapar HTML para título e conteúdo
    $title = sanitizeString($title, 200, false); // Não escapar HTML para título
    $content = sanitizeString($content, 50000, false); // Não escapar HTML para conteúdo
    $author = sanitizeString($author, 50);
    
    if (empty($title)) {
        handleError('Título da notícia é obrigatório');
    }
    
    if (empty($content)) {
        handleError('Conteúdo da notícia é obrigatório');
    }
    
    // Atualizar notícia
    $stmt = $db->prepare("UPDATE news SET title = ?, content = ?, author = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
    $stmt->execute([$title, $content, $author, $news_id]);
    
    // Processar novo arquivo se fornecido
    $hasFile = !empty($_FILES['edit_news_file']['tmp_name']) && $_FILES['edit_news_file']['size'] > 0;
    
    if ($hasFile) {
        // Primeiro, excluir arquivos antigos
        $stmt = $db->prepare('SELECT media, thumb FROM news WHERE id = ?');
        $stmt->execute([$news_id]);
        $oldNews = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($oldNews) {
            if (!empty($oldNews['media'])) {
                deleteFileIfExists($oldNews['media']);
            }
            if (!empty($oldNews['thumb'])) {
                deleteFileIfExists($oldNews['thumb']);
            }
        }
        
        // Processar novo arquivo - CORREÇÃO: Usando diretório de notícias
        $tmp = $_FILES['edit_news_file']['tmp_name'];
        $type = mime_content_type($tmp);

        if (!in_array($type, ALLOWED_FILE_TYPES, true)) {
            handleError('Tipo de arquivo inválido: ' . $type);
        }
        
        if ($_FILES['edit_news_file']['size'] > MAX_UPLOAD_SIZE) {
            handleError('Arquivo muito grande. Tamanho máximo: ' . round(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB');
        }

        $mediaPath = null;
        $thumbPath = null;
        
        if (str_starts_with($type, 'image/')) {
            // CORREÇÃO: Usa função específica para notícias
            $result = processAndOptimizeNewsImage($tmp, $_FILES['edit_news_file']['name']);
            $mediaPath = $result['main'];
            $thumbPath = $result['thumb'];
        } elseif (str_starts_with($type, 'video/')) {
            // CORREÇÃO: Usa função específica para notícias
            $result = processNewsVideo($tmp, $_FILES['edit_news_file']['name']);
            $mediaPath = $result['main'];
            $thumbPath = $result['thumb'];
        }
        
        // Atualizar caminhos dos arquivos
        $stmt = $db->prepare("UPDATE news SET media = ?, thumb = ? WHERE id = ?");
        $stmt->execute([$mediaPath, $thumbPath, $news_id]);
    }
    
    // Regenerar página HTML única
    generateNewsPages();
    
    $_SESSION['success'] = 'Notícia atualizada com sucesso!';
    
    // Limpar buffer antes de redirecionar
    ob_end_clean();
    header('Location: mod.php?section=news');
    exit;
}

/* =========================
   LIDAR COM EDIÇÃO DE POSTS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_submit'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        handleError('Token CSRF inválido', 403);
    }
    
    $new_message = trim($_POST['message'] ?? '');
    $new_message = sanitizeString($new_message, 5000, false); // Não escapar HTML
    
    if (strlen($new_message) > 0) {
        $id = sanitizeInt($_POST['id']);
        
        // CORREÇÃO: Usa tabela unificada posts
        $updateStmt = $db->prepare('UPDATE posts SET message = ? WHERE id = ?');
        $updateStmt->execute([$new_message, $id]);
    }
    
    $redirect_id = sanitizeInt($_POST['post_id'] ?? 0);
    
    // Limpar buffer antes de redirecionar
    ob_end_clean();
    header('Location: mod.php' . ($redirect_id ? '?post_id=' . $redirect_id : ''));
    exit;
}

// =========================
// LIDAR COM POSTAGEM DO MOD.PHP
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post']) && $canmanage) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        handleError('Token CSRF inválido', 403);
    }

    $name = trim($_POST['name'] ?? '');
    $body = trim($_POST['body'] ?? '');
    
    // Sanitizar inputs - NÃO escapar HTML para o corpo da mensagem
    $name = sanitizeString($name, 35);
    $body = sanitizeString($body, 5000, false); // Não escapar HTML para o corpo
    
    // Se o nome estiver vazio, usar padrão "admin"
    if ($name === '') {
        $name = 'admin';
    }
    
    if ($body === '') handleError('Mensagem obrigatória');
    
    // Verificar se a opção spoiler está selecionada
    $spoilered = 0;
    $hasFile = !empty($_FILES['file']['tmp_name']) && $_FILES['file']['size'] > 0;
    
    if ($hasFile) {
        $spoilered = isset($_POST['spoiler']) && $_POST['spoiler'] === 'on' ? 1 : 0;
    }

    $iphash = substr(sha1($_SERVER['REMOTE_ADDR'] ?? ''), 0, 16);

    if (!empty($_POST['thread'])) {
        $pid = sanitizeInt($_POST['thread']);
        
        // Verificar se o thread existe e não está bloqueado
        $stmt = $db->prepare('SELECT locked FROM posts WHERE id = ? AND deleted = 0 AND is_reply = 0');
        $stmt->execute([$pid]);
        $thread = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$thread) {
            handleError('Tópico não encontrado ou excluído.', 404);
        }
        
        // CORREÇÃO: Não permitir respostas se o tópico estiver trancado
        if (($thread['locked'] ?? 0) == 1) {
            handleError('Este tópico está trancado. Não é possível adicionar respostas.', 403);
        }
        
        // Lidar com upload de arquivo para respostas
        $mediaPath = null;
        $thumbPath = null;
        
        if ($hasFile) {
            $tmp = $_FILES['file']['tmp_name'];
            $type = mime_content_type($tmp);

            if (!in_array($type, ALLOWED_FILE_TYPES, true)) {
                handleError('Tipo de arquivo inválido: ' . $type);
            }
            
            if ($_FILES['file']['size'] > MAX_UPLOAD_SIZE) {
                handleError('Arquivo muito grande. Tamanho máximo: ' . round(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB');
            }

            // Verificar se é um arquivo de imagem/vídeo real
            if (str_starts_with($type, 'image/')) {
                $imageInfo = @getimagesize($tmp);
                if (!$imageInfo) {
                    handleError('Arquivo de imagem inválido.');
                }
                
                $result = processAndOptimizeImage($tmp, $uploadsDir, $_FILES['file']['name']);
                $mediaPath = $result['main'];
                $thumbPath = $result['thumb'];
            } elseif (str_starts_with($type, 'video/')) {
                $result = processVideo($tmp, $uploadsDir, $_FILES['file']['name']);
                $mediaPath = $result['main'];
                $thumbPath = $result['thumb'];
            }
        }
        
        // CORREÇÃO: Inserir resposta na tabela unificada posts com is_reply = 1
        // Posts do admin sempre são aprovados automaticamente
        $stmt = $db->prepare("INSERT INTO posts (message, media, thumb, name, postiphash, spoilered, capcode, is_reply, parent_id, approved) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, 1)");
        $stmt->execute([$body, $mediaPath, $thumbPath, $name, $iphash, $spoilered, 'admin', $pid]);
        
        // Obter o ID da resposta inserida
        $new_reply_id = $db->lastInsertId();
        
        // Registrar menções
        registerMentions($db, $new_reply_id, $body);
        
        // Atualizar timestamp do post
        $db->prepare("UPDATE posts SET updated_at = datetime('now', 'localtime') WHERE id = ?")->execute([$pid]);
        
        // Limpar buffer antes de redirecionar
        ob_end_clean();
        header("Location: mod.php?post_id=$pid");
        exit;
    }

    $subject = trim($_POST['subject'] ?? '');
    $subject = sanitizeString($subject, 100);
    
    $mediaPath = null;
    $thumbPath = null;
    
    if ($hasFile) {
        $tmp = $_FILES['file']['tmp_name'];
        $type = mime_content_type($tmp);

        if (!in_array($type, ALLOWED_FILE_TYPES, true)) {
            handleError('Tipo de arquivo inválido: ' . $type);
        }
        
        if ($_FILES['file']['size'] > MAX_UPLOAD_SIZE) {
            handleError('Arquivo muito grande. Tamanho máximo: ' . round(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB');
        }

        // Verificar se é um arquivo de imagem/vídeo real
        if (str_starts_with($type, 'image/')) {
            $imageInfo = @getimagesize($tmp);
            if (!$imageInfo) {
                handleError('Arquivo de imagem inválido.');
            }
            
            $result = processAndOptimizeImage($tmp, $uploadsDir, $_FILES['file']['name']);
            $mediaPath = $result['main'];
            $thumbPath = $result['thumb'];
        } elseif (str_starts_with($type, 'video/')) {
            $result = processVideo($tmp, $uploadsDir, $_FILES['file']['name']);
            $mediaPath = $result['main'];
            $thumbPath = $result['thumb'];
        }
    }

    // CORREÇÃO: Inserir post na tabela unificada com is_reply = 0
    // Posts do admin sempre são aprovados automaticamente
    $stmt = $db->prepare("INSERT INTO posts (title, message, media, thumb, name, postiphash, spoilered, capcode, is_reply, approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)");
    $stmt->execute([$subject, $body, $mediaPath, $thumbPath, $name, $iphash, $spoilered, 'admin']);
    
    // Obter o ID do post inserido
    $new_post_id = $db->lastInsertId();
    
    // Registrar menções
    registerMentions($db, $new_post_id, $body);
    
    // Limpar buffer antes de redirecionar
    ob_end_clean();
    header('Location: mod.php');
    exit;
}

/* =========================
   LIDAR COM AÇÕES DE MOD COM VALIDAÇÃO
========================= */
$action = $_GET['action'] ?? '';
$id = sanitizeInt($_GET['id'] ?? 0);
$post_id = sanitizeInt($_GET['post_id'] ?? 0);

if ($action && $canmanage) {
    // Verificar CSRF para ações destrutivas
    $destructiveActions = ['delete', 'delete_reply', 'delete_news', 'lock', 'sticky', 'approve_post', 'approve_reply', 'reject_post', 'reject_reply'];
    if (in_array($action, $destructiveActions)) {
        if (!isset($_GET['csrf']) || !validateCsrfToken($_GET['csrf'])) {
            handleError('Token CSRF inválido para esta ação', 403);
        }
    }
    
    // ===== AÇÕES DE APROVAÇÃO =====
    if ($action === 'approve_post') {
        // Aprovar tópico
        $stmt = $db->prepare('UPDATE posts SET approved = 1, approved_at = datetime("now", "localtime") WHERE id = ?');
        $stmt->execute([$id]);
        
        $_SESSION['success'] = 'Tópico aprovado com sucesso!';
        
        ob_end_clean();
        header('Location: mod.php?section=pending');
        exit;
        
    } elseif ($action === 'approve_reply') {
        // Aprovar resposta
        $stmt = $db->prepare('UPDATE posts SET approved = 1, approved_at = datetime("now", "localtime") WHERE id = ?');
        $stmt->execute([$id]);
        
        $_SESSION['success'] = 'Resposta aprovada com sucesso!';
        
        ob_end_clean();
        header('Location: mod.php?section=pending');
        exit;
        
    } elseif ($action === 'reject_post') {
        // Rejeitar e excluir tópico
        // Primeiro, obter informações para excluir arquivos
        $stmt = $db->prepare('SELECT media, thumb FROM posts WHERE id = ?');
        $stmt->execute([$id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($post) {
            // Excluir arquivos do post
            if (!empty($post['media'])) {
                deleteFileIfExists($post['media']);
            }
            if (!empty($post['thumb'])) {
                deleteFileIfExists($post['thumb']);
            }
        }
        
        // Excluir todas as respostas associadas e seus arquivos
        $repliesStmt = $db->prepare('SELECT media, thumb FROM posts WHERE parent_id = ?');
        $repliesStmt->execute([$id]);
        $replies = $repliesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($replies as $reply) {
            if (!empty($reply['media'])) {
                deleteFileIfExists($reply['media']);
            }
            if (!empty($reply['thumb'])) {
                deleteFileIfExists($reply['thumb']);
            }
        }
        
        // Excluir o post e todas as suas respostas
        $db->prepare('DELETE FROM posts WHERE id = ? OR parent_id = ?')->execute([$id, $id]);
        
        $_SESSION['success'] = 'Tópico rejeitado e excluído permanentemente!';
        
        ob_end_clean();
        header('Location: mod.php?section=pending');
        exit;
        
    } elseif ($action === 'reject_reply') {
        // Rejeitar e excluir resposta
        // Primeiro, obter informações para excluir arquivos
        $stmt = $db->prepare('SELECT media, thumb FROM posts WHERE id = ?');
        $stmt->execute([$id]);
        $reply = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reply) {
            // Excluir arquivos da resposta
            if (!empty($reply['media'])) {
                deleteFileIfExists($reply['media']);
            }
            if (!empty($reply['thumb'])) {
                deleteFileIfExists($reply['thumb']);
            }
        }
        
        // Obter o ID do tópico pai para redirecionamento
        $parentStmt = $db->prepare('SELECT parent_id FROM posts WHERE id = ?');
        $parentStmt->execute([$id]);
        $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
        $parent_id = $parent ? $parent['parent_id'] : 0;
        
        // Excluir a resposta
        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        
        $_SESSION['success'] = 'Resposta rejeitada e excluída permanentemente!';
        
        ob_end_clean();
        header('Location: mod.php?section=pending');
        exit;
        
    } elseif ($action === 'lock') {
        // CORREÇÃO: Alternar status de bloqueio na tabela unificada
        $stmt = $db->prepare('UPDATE posts SET locked = 1 - locked WHERE id = ?');
        $stmt->execute([$id]);
        
        // Limpar buffer antes de redirecionar
        ob_end_clean();
        header('Location: mod.php' . ($post_id ? '?post_id=' . $post_id : ''));
        exit;
        
    } elseif ($action === 'sticky') {
        // CORREÇÃO: Alternar status de fixação na tabela unificada
        $stmt = $db->prepare('UPDATE posts SET sticky = 1 - sticky WHERE id = ?');
        $stmt->execute([$id]);
        
        // Limpar buffer antes de redirecionar
        ob_end_clean();
        header('Location: mod.php' . ($post_id ? '?post_id=' . $post_id : ''));
        exit;
        
    } elseif ($action === 'delete') {
        // ADMIN: Exclusão permanente
        deletePostFiles($db, $id);
        
        // CORREÇÃO: Excluir permanentemente da tabela unificada
        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        
        $_SESSION['success'] = 'Post excluído permanentemente!';
        
        // Limpar buffer antes de redirecionar
        ob_end_clean();
        header('Location: mod.php');
        exit;
        
    } elseif ($action === 'delete_reply') {
        // ADMIN: Exclusão permanente de resposta
        deleteReplyFile($db, $id);
        
        // CORREÇÃO: Excluir resposta da tabela unificada
        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        
        $_SESSION['success'] = 'Resposta excluída permanentemente!';
        
        // Limpar buffer antes de redirecionar
        ob_end_clean();
        header('Location: mod.php' . ($post_id ? '?post_id=' . $post_id : ''));
        exit;
        
    } elseif ($action === 'edit' || $action === 'edit_reply') {
        // Formulário de edição - CORREÇÃO: Usa tabela unificada
        $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            die('Item não encontrado.');
        }
        
        $type = $item['is_reply'] == 1 ? 'reply' : 'post';
        
        // Limpar buffer antes da saída
        ob_end_clean();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Editar - Moderação</title>
            <style>
                body {
                    background-color: #eef2ff;
                    font-family: arial,helvetica,sans-serif;
                    font-size: 10pt;
                    padding: 20px;
                }
                .edit-form {
                    max-width: 600px;
                    margin: 50px auto;
                    background-color: #f0e0d6;
                    padding: 20px;
                    border: 1px solid #d9bfb7;
                }
                textarea {
                    width: 100%;
                    height: 200px;
                    margin-bottom: 10px;
                }
                input[type="submit"] {
                    background-color: #34345c;
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    cursor: pointer;
                }
                input[type="submit"]:hover {
                    background-color: #af0a0f;
                }
            </style>
        </head>
        <body>
            <div class="edit-form">
                <h2>Editar <?php echo $type === 'post' ? 'Tópico' : 'Resposta'; ?></h2>
                <form method="POST" action="mod.php">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="post_id" value="<?php echo $post_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <textarea name="message"><?php echo htmlspecialchars($item['message']); ?></textarea><br>
                    <input type="submit" name="edit_submit" value="Salvar">
                    <a href="mod.php<?php echo $post_id ? '?post_id=' . $post_id : ''; ?>" style="margin-left: 10px;">Cancelar</a>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// =========================
// VERIFICAÇÃO E CRIAÇÃO DAS TABELAS DO BANCO DE DADOS
// =========================

// CORREÇÃO: Verificar e criar tabela unificada posts se necessário
$db->exec("
CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    message TEXT,
    media TEXT,
    thumb TEXT,
    name TEXT,
    created_at DATETIME DEFAULT (datetime('now', 'localtime')),
    updated_at DATETIME DEFAULT (datetime('now', 'localtime')),
    deleted INTEGER DEFAULT 0,
    postiphash TEXT,
    spoilered INTEGER DEFAULT 0,
    locked INTEGER DEFAULT 0,
    sticky INTEGER DEFAULT 0,
    capcode TEXT DEFAULT NULL,
    is_reply INTEGER DEFAULT 0,
    parent_id INTEGER DEFAULT NULL,
    approved INTEGER DEFAULT 1,
    approved_at DATETIME,
    FOREIGN KEY(parent_id) REFERENCES posts(id) ON DELETE CASCADE
)");

// Adicionar coluna approved_at se não existir
$checkApprovedAt = $db->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_ASSOC);
$hasApprovedAt = false;
foreach ($checkApprovedAt as $col) {
    if ($col['name'] === 'approved_at') {
        $hasApprovedAt = true;
        break;
    }
}
if (!$hasApprovedAt) {
    $db->exec("ALTER TABLE posts ADD COLUMN approved_at DATETIME");
}

$db->exec("
CREATE TABLE IF NOT EXISTS news (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    author TEXT NOT NULL DEFAULT 'Admin',
    media TEXT,
    thumb TEXT,
    created_at DATETIME DEFAULT (datetime('now', 'localtime')),
    updated_at DATETIME DEFAULT (datetime('now', 'localtime')),
    is_active INTEGER DEFAULT 1,
    view_count INTEGER DEFAULT 0
)");

// ===== TABELA DE MENÇÕES =====
$db->exec("
CREATE TABLE IF NOT EXISTS mentions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL,
    target_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT (datetime('now', 'localtime')),
    FOREIGN KEY(source_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY(target_id) REFERENCES posts(id) ON DELETE CASCADE
)");

// Verificar se todas as colunas necessárias existem na tabela news
function verifyNewsTableColumns($db) {
    $requiredColumns = [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'title' => 'TEXT NOT NULL',
        'content' => 'TEXT NOT NULL',
        'author' => 'TEXT NOT NULL DEFAULT \'Admin\'',
        'media' => 'TEXT',
        'thumb' => 'TEXT',
        'created_at' => 'DATETIME DEFAULT (datetime(\'now\', \'localtime\'))',
        'updated_at' => 'DATETIME DEFAULT (datetime(\'now\', \'localtime\'))',
        'is_active' => 'INTEGER DEFAULT 1',
        'view_count' => 'INTEGER DEFAULT 0'
    ];
    
    // Verificar quais colunas existem
    $result = $db->query("PRAGMA table_info(news)");
    $existingColumns = [];
    while ($col = $result->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[$col['name']] = true;
    }
    
    // Adicionar colunas ausentes
    foreach ($requiredColumns as $column => $definition) {
        if (!isset($existingColumns[$column])) {
            try {
                $db->exec("ALTER TABLE news ADD COLUMN $column $definition");
                error_log("Coluna $column adicionada à tabela news");
            } catch (PDOException $e) {
                error_log('Erro ao adicionar coluna ' . $column . ': ' . $e->getMessage());
            }
        }
    }
}

// Executar verificação das colunas
verifyNewsTableColumns($db);

// Adicionar índices para performance
$db->exec('CREATE INDEX IF NOT EXISTS idx_posts_is_reply ON posts(is_reply)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_posts_parent ON posts(parent_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_posts_updated_at ON posts(updated_at DESC)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_posts_approved ON posts(approved)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_news_created_at ON news(created_at DESC)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_mentions_source ON mentions(source_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_mentions_target ON mentions(target_id)');

// Migrar dados da tabela antiga de respostas se existir
$migration_check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='replies'")->fetch();
if ($migration_check) {
    try {
        // Verificar se já fez a migração
        $check_migrated = $db->query("SELECT COUNT(*) FROM posts WHERE is_reply = 1")->fetchColumn();
        
        if ($check_migrated == 0) {
            // Marcar posts existentes como threads (não replies)
            $db->exec("UPDATE posts SET is_reply = 0 WHERE is_reply IS NULL");
            
            // Migrar replies para a nova estrutura
            $replies = $db->query("SELECT * FROM replies")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($replies as $reply) {
                // Verificar se o ID já existe
                $exists = $db->prepare("SELECT COUNT(*) FROM posts WHERE id = ?");
                $exists->execute([$reply['id']]);
                
                if ($exists->fetchColumn() == 0) {
                    $stmt = $db->prepare("INSERT INTO posts 
                        (id, message, media, thumb, name, created_at, deleted, postiphash, spoilered, capcode, is_reply, parent_id, approved) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 1)");
                    $stmt->execute([
                        $reply['id'],
                        $reply['message'],
                        $reply['media'] ?? null,
                        $reply['thumb'] ?? null,
                        $reply['name'] ?? 'Anônimo',
                        $reply['created_at'],
                        $reply['deleted'] ?? 0,
                        $reply['postiphash'] ?? null,
                        $reply['spoilered'] ?? 0,
                        $reply['capcode'] ?? null,
                        $reply['post_id']
                    ]);
                }
            }
            
            // Atualizar contador AUTOINCREMENT
            $max_id = $db->query("SELECT MAX(id) FROM posts")->fetchColumn();
            $db->exec("UPDATE sqlite_sequence SET seq = $max_id WHERE name = 'posts'");
            
            // Renomear tabela antiga em vez de dropar (segurança)
            $db->exec("ALTER TABLE replies RENAME TO replies_backup_" . date('YmdHis'));
        }
    } catch (Exception $e) {
        error_log("Erro na migração: " . $e->getMessage());
    }
}

/* =========================
   FUNÇÕES DE PROCESSAMENTO DE ARQUIVOS
========================= */
function getHashedFilename(string $originalName): string {
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $hash = hash('sha256', $originalName . microtime() . random_bytes(16));
    return substr($hash, 0, 32) . ($ext ? ".$ext" : '');
}

function isAnimatedGif(string $filepath): bool {
    if (mime_content_type($filepath) !== 'image/gif') {
        return false;
    }
    
    $fh = fopen($filepath, 'rb');
    if (!$fh) return false;
    
    $frames = 0;
    while (!feof($fh) && $frames < 2) {
        $chunk = fread($fh, 1024 * 100);
        $frames += substr_count($chunk, "\x00\x21\xF9\x04");
    }
    
    fclose($fh);
    return $frames > 1;
}

function createThumbnailGD(string $source, string $dest, int $maxWidth, int $maxHeight, bool $useWebp = true): ?string {
    if (!file_exists($source)) return null;
    
    $imageInfo = getimagesize($source);
    if (!$imageInfo) return null;
    
    list($origWidth, $origHeight, $type) = $imageInfo;
    
    // Calculate new dimensions
    $ratio = $origWidth / $origHeight;
    $newWidth = min($maxWidth, $origWidth);
    $newHeight = min($maxHeight, $origHeight);
    
    if ($newWidth / $newHeight > $ratio) {
        $newWidth = $newHeight * $ratio;
    } else {
        $newHeight = $newWidth / $ratio;
    }
    
    $newWidth = (int)max(1, $newWidth);
    $newHeight = (int)max(1, $newHeight);
    
    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $srcImage = imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $srcImage = imagecreatefrompng($source);
            break;
        case IMAGETYPE_GIF:
            $srcImage = imagecreatefromgif($source);
            break;
        case IMAGETYPE_WEBP:
            $srcImage = imagecreatefromwebp($source);
            break;
        default:
            return null;
    }
    
    if (!$srcImage) return null;
    
    // Create thumbnail
    $dstImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG/GIF
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF || $type === IMAGETYPE_WEBP) {
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
        $transparent = imagecolorallocatealpha($dstImage, 255, 255, 255, 127);
        imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
    
    // Save thumbnail
    if ($useWebp) {
        $dest = preg_replace('/\.[^.]+$/', '.webp', $dest);
        imagewebp($dstImage, $dest, IMAGE_QUALITY);
    } else {
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($dstImage, $dest, IMAGE_QUALITY);
                break;
            case IMAGETYPE_PNG:
                imagepng($dstImage, $dest, 9);
                break;
            case IMAGETYPE_GIF:
                imagegif($dstImage, $dest);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($dstImage, $dest, IMAGE_QUALITY);
                break;
        }
    }
    
    return file_exists($dest) ? $dest : null;
}

// CORREÇÃO: Função para processar imagens de notícias (usa diretório diferente)
function processAndOptimizeNewsImage(string $tmpPath, string $originalName): array {
    global $newsUploadsDir, $newsThumbsDir;
    
    $result = ['main' => null, 'thumb' => null];
    
    $hashedName = getHashedFilename($originalName);
    $mainPath = $newsUploadsDir . $hashedName;
    
    // Determine if we should convert to WebP
    $mime = mime_content_type($tmpPath);
    $convertToWebP = USE_WEBP && str_starts_with($mime, 'image/');
    
    if ($convertToWebP) {
        // Convert main image to WebP
        $imageInfo = getimagesize($tmpPath);
        if (!$imageInfo) return $result;
        
        list($origWidth, $origHeight, $type) = $imageInfo;
        
        // Create image resource
        switch ($type) {
            case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($tmpPath); break;
            case IMAGETYPE_PNG: $src = imagecreatefrompng($tmpPath); break;
            case IMAGETYPE_GIF: 
                // Preserve GIF animations
                if (isAnimatedGif($tmpPath)) {
                    copy($tmpPath, $mainPath);
                    $result['main'] = $mainPath;
                    // Create thumbnail for animated GIF
                    if (GENERATE_THUMBNAILS) {
                        $thumbPath = createThumbnailGD($tmpPath, $newsThumbsDir . $hashedName, 
                            THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT, false);
                        if ($thumbPath) $result['thumb'] = $thumbPath;
                    }
                    return $result;
                }
                $src = imagecreatefromgif($tmpPath);
                break;
            case IMAGETYPE_WEBP: $src = imagecreatefromwebp($tmpPath); break;
            default: return $result;
        }
        
        if (!$src) return $result;
        
        // Save as WebP
        $webpPath = preg_replace('/\.[^.]+$/', '.webp', $mainPath);
        imagewebp($src, $webpPath, IMAGE_QUALITY);
        
        $result['main'] = $webpPath;
        
        // Create thumbnail
        if (GENERATE_THUMBNAILS) {
            $thumbPath = createThumbnailGD($webpPath, $newsThumbsDir . basename($webpPath), 
                THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT, true);
            if ($thumbPath) $result['thumb'] = $thumbPath;
        }
    } else {
        // Keep original format
        if (!move_uploaded_file($tmpPath, $mainPath)) {
            copy($tmpPath, $mainPath);
        }
        $result['main'] = $mainPath;
        
        // Create thumbnail
        if (GENERATE_THUMBNAILS && str_starts_with($mime, 'image/')) {
            $thumbPath = createThumbnailGD($mainPath, $newsThumbsDir . $hashedName, 
                THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT, false);
            if ($thumbPath) $result['thumb'] = $thumbPath;
        }
    }
    
    return $result;
}

// CORREÇÃO: Função para processar vídeos de notícias (usa diretório diferente)
function processNewsVideo(string $tmpPath, string $originalName): array {
    global $newsUploadsDir, $newsThumbsDir;
    
    $result = ['main' => null, 'thumb' => null];
    
    $hashedName = getHashedFilename($originalName);
    $mainPath = $newsUploadsDir . $hashedName;
    
    // Move video file
    if (!move_uploaded_file($tmpPath, $mainPath)) {
        copy($tmpPath, $mainPath);
    }
    $result['main'] = $mainPath;
    
    // Try to generate thumbnail from video (first frame)
    if (GENERATE_THUMBNAILS) {
        $thumbName = preg_replace('/\.[^.]+$/', '.jpg', $hashedName);
        $thumbPath = $newsThumbsDir . $thumbName;
        
        // Try using ffmpeg if available
        if (function_exists('shell_exec')) {
            $ffmpegCmd = "ffmpeg -i " . escapeshellarg($mainPath) . 
                        " -ss 00:00:01 -vframes 1 -vf 'scale=" . THUMB_MAX_WIDTH . ":" . THUMB_MAX_HEIGHT . 
                        ":force_original_aspect_ratio=decrease' " . escapeshellarg($thumbPath) . 
                        " 2>&1";
            
            @shell_exec($ffmpegCmd);
            
            if (file_exists($thumbPath)) {
                $result['thumb'] = $thumbPath;
            }
        }
    }
    
    return $result;
}

// Funções originais para posts normais (não alteradas)
function processAndOptimizeImage(string $tmpPath, string $uploadsDir, string $originalName): array {
    $result = ['main' => null, 'thumb' => null];
    
    $hashedName = getHashedFilename($originalName);
    $mainPath = $uploadsDir . $hashedName;
    
    // Determine if we should convert to WebP
    $mime = mime_content_type($tmpPath);
    $convertToWebP = USE_WEBP && str_starts_with($mime, 'image/');
    
    if ($convertToWebP) {
        // Convert main image to WebP
        $imageInfo = getimagesize($tmpPath);
        if (!$imageInfo) return $result;
        
        list($origWidth, $origHeight, $type) = $imageInfo;
        
        // Create image resource
        switch ($type) {
            case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($tmpPath); break;
            case IMAGETYPE_PNG: $src = imagecreatefrompng($tmpPath); break;
            case IMAGETYPE_GIF: 
                // Preserve GIF animations
                if (isAnimatedGif($tmpPath)) {
                    copy($tmpPath, $mainPath);
                    $result['main'] = $mainPath;
                    // Create thumbnail for animated GIF
                    if (GENERATE_THUMBNAILS) {
                        $thumbPath = createThumbnailGD($tmpPath, $uploadsDir . 'thumbs/' . $hashedName, 
                            THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT, false);
                        if ($thumbPath) $result['thumb'] = $thumbPath;
                    }
                    return $result;
                }
                $src = imagecreatefromgif($tmpPath);
                break;
            case IMAGETYPE_WEBP: $src = imagecreatefromwebp($tmpPath); break;
            default: return $result;
        }
        
        if (!$src) return $result;
        
        // Save as WebP
        $webpPath = preg_replace('/\.[^.]+$/', '.webp', $mainPath);
        imagewebp($src, $webpPath, IMAGE_QUALITY);
        
        $result['main'] = $webpPath;
        
        // Create thumbnail
        if (GENERATE_THUMBNAILS) {
            $thumbPath = createThumbnailGD($webpPath, $uploadsDir . 'thumbs/' . basename($webpPath), 
                THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT, true);
            if ($thumbPath) $result['thumb'] = $thumbPath;
        }
    } else {
        // Keep original format
        if (!move_uploaded_file($tmpPath, $mainPath)) {
            copy($tmpPath, $mainPath);
        }
        $result['main'] = $mainPath;
        
        // Create thumbnail
        if (GENERATE_THUMBNAILS && str_starts_with($mime, 'image/')) {
            $thumbPath = createThumbnailGD($mainPath, $uploadsDir . 'thumbs/' . $hashedName, 
                THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT, false);
            if ($thumbPath) $result['thumb'] = $thumbPath;
        }
    }
    
    return $result;
}

function processVideo(string $tmpPath, string $uploadsDir, string $originalName): array {
    $result = ['main' => null, 'thumb' => null];
    
    $hashedName = getHashedFilename($originalName);
    $mainPath = $uploadsDir . $hashedName;
    
    // Move video file
    if (!move_uploaded_file($tmpPath, $mainPath)) {
        copy($tmpPath, $mainPath);
    }
    $result['main'] = $mainPath;
    
    // Try to generate thumbnail from video (first frame)
    if (GENERATE_THUMBNAILS) {
        $thumbName = preg_replace('/\.[^.]+$/', '.jpg', $hashedName);
        $thumbPath = $uploadsDir . 'thumbs/' . $thumbName;
        
        // Try using ffmpeg if available
        if (function_exists('shell_exec')) {
            $ffmpegCmd = "ffmpeg -i " . escapeshellarg($mainPath) . 
                        " -ss 00:00:01 -vframes 1 -vf 'scale=" . THUMB_MAX_WIDTH . ":" . THUMB_MAX_HEIGHT . 
                        ":force_original_aspect_ratio=decrease' " . escapeshellarg($thumbPath) . 
                        " 2>&1";
            
            @shell_exec($ffmpegCmd);
            
            if (file_exists($thumbPath)) {
                $result['thumb'] = $thumbPath;
            }
        }
    }
    
    return $result;
}

/* =========================
   FUNÇÕES DE EXCLUSÃO DE ARQUIVOS
========================= */
function deleteFileIfExists(?string $filePath): bool {
    if ($filePath && file_exists($filePath)) {
        return unlink($filePath);
    }
    return false;
}

function deletePostFiles(PDO $db, int $post_id): void {
    // CORREÇÃO: Buscar arquivos do post na tabela unificada
    $postStmt = $db->prepare('SELECT media, thumb FROM posts WHERE id = ?');
    $postStmt->execute([$post_id]);
    $post = $postStmt->fetch(PDO::FETCH_ASSOC);
    
    // Excluir arquivos do post
    if ($post) {
        if ($post['media']) {
            deleteFileIfExists($post['media']);
        }
        if ($post['thumb']) {
            deleteFileIfExists($post['thumb']);
        }
    }
    
    // Se for um tópico, também excluir arquivos de todas as respostas
    $replyStmt = $db->prepare('SELECT media, thumb FROM posts WHERE parent_id = ?');
    $replyStmt->execute([$post_id]);
    $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Excluir arquivos de resposta
    foreach ($replies as $reply) {
        if ($reply['media']) {
            deleteFileIfExists($reply['media']);
        }
        if ($reply['thumb']) {
            deleteFileIfExists($reply['thumb']);
        }
    }
}

function deleteReplyFile(PDO $db, int $reply_id): void {
    $stmt = $db->prepare('SELECT media, thumb FROM posts WHERE id = ?');
    $stmt->execute([$reply_id]);
    $reply = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reply) {
        if ($reply['media']) {
            deleteFileIfExists($reply['media']);
        }
        if ($reply['thumb']) {
            deleteFileIfExists($reply['thumb']);
        }
    }
}

/* =========================
   FUNÇÕES DE RENDERIZAÇÃO
========================= */

// ==================== FIXED formatPostText FUNCTION - Preserves special characters ====================
function formatPostText(string $text): string {
    $lines = explode("\n", $text);
    $processed = [];
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $processed[] = '<br>';
            continue;
        }
        
        // Detectar greentext - NÃO aplicar htmlspecialchars para preservar caracteres especiais
        if (str_starts_with($trimmed, '>')) {
            $processed[] = '<span class="greentext">' . $line . '</span><br>';
        } 
        // Detectar spoiler manual (&&spoiler&&)
        elseif (preg_match('/&&spoiler&&(.*?)&&\/spoiler&&/s', $line, $matches)) {
            $spoilerContent = $matches[1];
            $processed[] = '<span class="spoiler-text" onclick="this.classList.toggle(\'revealed\')">' . $spoilerContent . '</span><br>';
        }
        // Detectar texto vermelho (mod only)
        elseif (str_starts_with($trimmed, '%%')) {
            $processed[] = '<span class="redtext">' . substr($line, 2) . '</span><br>';
        }
        // Linkar posts - Para texto normal, não aplicar htmlspecialchars
        else {
            $linked = preg_replace(
                '/(?:&gt;&gt;|>>)(\d+)/',
                '<a href="#p$1" class="post_ref">>>$1</a>',
                $line
            );
            $processed[] = $linked . '<br>';
        }
    }
    
    return implode('', $processed);
}

function renderMedia(?string $mediaPath, ?string $thumbPath, int $postId, bool $isSpoiler = false, string $type = 'post'): string {
    if (!$mediaPath || !file_exists($mediaPath)) {
        return '';
    }
    
    $mime = mime_content_type($mediaPath);
    $filename = htmlspecialchars(basename($mediaPath));
    $fileSize = file_exists($mediaPath) ? filesize($mediaPath) : 0;
    $sizeKB = round($fileSize / 1024, 1);
    
    // Determinar tipo de arquivo
    $isImage = str_starts_with($mime, 'image/');
    $isVideo = str_starts_with($mime, 'video/');
    
    $html = '<div class="file">';
    
    if ($isImage) {
        if ($isSpoiler && $thumbPath && file_exists($thumbPath)) {
            $html .= '<a href="' . htmlspecialchars($mediaPath) . '" target="_blank">';
            $html .= '<img src="' . htmlspecialchars($thumbPath) . '" alt="Spoiler" class="spoiler-img" ';
            $html .= 'onclick="this.classList.toggle(\'revealed\');" ';
            $html .= 'title="Clique para revelar/mostrar spoiler" style="cursor: pointer;" /></a>';
        } elseif ($thumbPath && file_exists($thumbPath)) {
            $html .= '<a href="' . htmlspecialchars($mediaPath) . '" target="_blank">';
            $html .= '<img src="' . htmlspecialchars($thumbPath) . '" alt="' . $filename . '" class="thumb" /></a>';
        } else {
            $html .= '<a href="' . htmlspecialchars($mediaPath) . '" target="_blank">';
            $html .= '<img src="' . htmlspecialchars($mediaPath) . '" alt="' . $filename . '" class="direct" style="max-width: 250px;" /></a>';
        }
    } elseif ($isVideo) {
        $html .= '<video controls preload="metadata" style="max-width: 250px;">';
        $html .= '<source src="' . htmlspecialchars($mediaPath) . '" type="' . $mime . '">';
        $html .= 'Seu navegador não suporta vídeo HTML5.';
        $html .= '</video>';
    }
    
    $html .= '<div class="fileinfo">';
    $html .= '<a href="' . htmlspecialchars($mediaPath) . '" target="_blank">' . $filename . '</a> ';
    $html .= '(' . $sizeKB . ' KB';
    
    if ($isImage) {
        $imageInfo = @getimagesize($mediaPath);
        if ($imageInfo) {
            $html .= ', ' . $imageInfo[0] . 'x' . $imageInfo[1];
        }
    } elseif ($isVideo) {
        $html .= ', vídeo';
    }
    
    $html .= ')';
    
    if ($isSpoiler) {
        $html .= ' <span class="spoiler-label">[Spoiler]</span>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

function renderReply(
    int $replyId, 
    string $message, 
    ?string $mediaPath, 
    ?string $thumbPath, 
    string $name, 
    string $createdAt, 
    int $postId, 
    bool $isDeleted = false,
    bool $isSpoiler = false,
    ?string $capcode = null,
    bool $isPending = false
): string {
    global $db, $csrf_token;
    
    if ($isDeleted) {
        return '<div class="post reply deleted" id="p' . $replyId . '">' .
               '<span class="intro">Resposta excluída.</span>' .
               '</div>';
    }
    
    $time = formatTime($createdAt);
    $formattedName = formatName($name, $capcode);
    $pendingClass = $isPending ? ' pending-post' : '';
    
    // Obter menções deste post
    $mentions = getPostMentions($db, $replyId);
    
    $html = '<div class="post reply' . $pendingClass . '" id="post-' . $replyId . '">';
    $html .= '<span class="intro">';
    $html .= '<input type="checkbox" name="delete" value="' . $replyId . '"> ';
    $html .= '<a class="post_no" href="mod.php?post_id=' . $postId . '#post-' . $replyId . '">#' . $replyId . '</a> ';
    
    if ($isPending) {
        $html .= '<span class="pending-badge" style="color: #ff9900; font-weight: bold;">[Pendente]</span> ';
    }
    
    $html .= '<span class="name">' . $formattedName . '</span> ';
    $html .= '<span class="post_time" title="' . $time['datetime'] . '">' . $time['formatted'] . '</span> ';
    
    // Badge de menções
    if (!empty($mentions)) {
        $mentionText = implode(' ', array_map(function($id) {
            return '<a href="#post-' . $id . '" class="mention-link mention-badge" onclick="highlightPost(' . $id . '); return false;">>>' . $id . '</a>';
        }, $mentions));
        $html .= '<span class="mention-badge-container"> ' . $mentionText . '</span>';
    }
    
    // Links de moderação para respostas
    $html .= '<span class="mod-links">';
    $html .= '[<a href="mod.php?action=edit_reply&id=' . $replyId . '&post_id=' . $postId . '">Editar</a>] ';
    
    if ($isPending) {
        $html .= '[<a href="mod.php?action=approve_reply&id=' . $replyId . '&csrf=' . urlencode($csrf_token) . '" onclick="return confirm(\'Aprovar esta resposta?\')">Aprovar</a>] ';
        $html .= '[<a href="mod.php?action=reject_reply&id=' . $replyId . '&csrf=' . urlencode($csrf_token) . '" onclick="return confirm(\'Rejeitar e excluir esta resposta?\')">Rejeitar</a>] ';
    }
    
    $html .= '[<a href="mod.php?action=delete_reply&id=' . $replyId . '&post_id=' . $postId . '&csrf=' . urlencode($csrf_token) . '" onclick="return confirm(\'Excluir esta resposta?\')">Excluir</a>]';
    $html .= '</span>';
    
    $html .= '</span><br>';
    
    // Mídia
    if ($mediaPath) {
        $html .= renderMedia($mediaPath, $thumbPath, $replyId, $isSpoiler, 'reply');
    }
    
    // Mensagem
    $html .= '<div class="body">';
    $html .= formatPostTextWithMentions($message, $db);
    $html .= '</div>';
    
    $html .= '</div>';
    
    return $html;
}

function renderPost(
    int $postId, 
    ?string $subject, 
    string $message, 
    ?string $mediaPath, 
    ?string $thumbPath, 
    string $name, 
    string $createdAt, 
    bool $isIndex = false, 
    bool $isDeleted = false,
    bool $isSpoiler = false,
    bool $isSticky = false,
    bool $isLocked = false,
    ?string $capcode = null,
    bool $isPending = false
): string {
    if ($isDeleted) {
        return '<div class="thread">' .
               '<div class="post op deleted">' .
               '<span class="intro">Post excluído.</span>' .
               '</div>' .
               '</div>';
    }
    
    $time = formatTime($createdAt);
    $formattedName = formatName($name, $capcode);
    $replyCount = 0;
    global $db, $csrf_token;
    
    if ($isIndex) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE parent_id = ? AND deleted = 0 AND is_reply = 1 AND approved = 1');
        $stmt->execute([$postId]);
        $replyCount = (int)$stmt->fetchColumn();
    }
    
    // Obter menções deste post
    $mentions = getPostMentions($db, $postId);
    
    // Classes CSS adicionais
    $cssClasses = ['thread'];
    if ($isSticky) $cssClasses[] = 'sticky-post';
    if ($isLocked) $cssClasses[] = 'locked-post';
    if ($isPending) $cssClasses[] = 'pending-post';
    
    $html = '<div class="' . implode(' ', $cssClasses) . '">';
    $html .= '<div class="post op" id="post-' . $postId . '">';
    $html .= '<span class="intro">';
    
    // Checkbox para exclusão em massa
    $html .= '<input type="checkbox" name="delete" value="' . $postId . '"> ';
    
    // Número do post
    $html .= '<a class="post_no" href="mod.php?post_id=' . $postId . '#post-' . $postId . '">#' . $postId . '</a> ';
    
    // Badges para sticky/locked/pending
    if ($isPending) {
        $html .= '<span class="pending-badge" style="color: #ff9900; font-weight: bold;">[Pendente]</span> ';
    }
    if ($isSticky) {
        $html .= '<span class="sticky-badge" style="color: #ff9900; font-weight: bold;">[Fixado]</span> ';
    }
    if ($isLocked) {
        $html .= '<span class="locked-badge" style="color: #ff0000; font-weight: bold;">[Trancado]</span> ';
    }
    
    // Assunto
    if ($subject && trim($subject) !== '') {
        $html .= '<span class="subject">' . htmlspecialchars($subject) . '</span> ';
    }
    
    // Nome
    $html .= '<span class="name">' . $formattedName . '</span> ';
    
    // Data/hora
    $html .= '<span class="post_time" title="' . $time['datetime'] . '">' . $time['formatted'] . '</span> ';
    
    // Contagem de respostas (somente no índice)
    if ($isIndex) {
        $html .= '<span class="reply_count">[' . $replyCount . ' respostas]</span> ';
    }
    
    // Badge de menções
    if (!empty($mentions)) {
        $mentionText = implode(' ', array_map(function($id) {
            return '<a href="#post-' . $id . '" class="mention-link mention-badge" onclick="highlightPost(' . $id . '); return false;">>>' . $id . '</a>';
        }, $mentions));
        $html .= '<span class="mention-badge-container"> ' . $mentionText . '</span>';
    }
    
    // Links de moderação
    $html .= '<span class="mod-links">';
    $html .= '[<a href="mod.php?action=edit&id=' . $postId . '">Editar</a>] ';
    
    if ($isPending) {
        $html .= '[<a href="mod.php?action=approve_post&id=' . $postId . '&csrf=' . urlencode($csrf_token) . '" onclick="return confirm(\'Aprovar este post?\')">Aprovar</a>] ';
        $html .= '[<a href="mod.php?action=reject_post&id=' . $postId . '&csrf=' . urlencode($csrf_token) . '" onclick="return confirm(\'Rejeitar e excluir este post?\')">Rejeitar</a>] ';
    }
    
    $html .= '[<a href="mod.php?action=lock&id=' . $postId . '&csrf=' . urlencode($csrf_token) . '" onclick="return confirm(\'' . ($isLocked ? 'Destrancar' : 'Trancar') . ' este tópico?\')">' . ($isLocked ? 'Destrancar' : 'Trancar') . '</a>] ';
    $html .= '[<a href="mod.php?action=sticky&id=' . $postId . '&csrf=' . urlencode($csrf_token) . '" onclick="return confirm(\'' . ($isSticky ? 'Desfixar' : 'Fixar') . ' este tópico?\')">' . ($isSticky ? 'Desfixar' : 'Fixar') . '</a>] ';
    $html .= '[<a href="mod.php?action=delete&id=' . $postId . '&csrf=' . urlencode($csrf_token) . '" onclick="return confirm(\'Excluir este tópico e todas as respostas?\')">Excluir</a>]';
    $html .= '</span>';
    
    $html .= '</span><br>';
    
    // Mídia
    if ($mediaPath) {
        $html .= renderMedia($mediaPath, $thumbPath, $postId, $isSpoiler, 'post');
    }
    
    // Mensagem
    $html .= '<div class="body">';
    
    if ($isIndex) {
        // No índice, mostrar prévia da mensagem
        $preview = strlen($message) > MAX_PREVIEW_CHARS 
            ? substr($message, 0, MAX_PREVIEW_CHARS) . '...' 
            : $message;
        $html .= formatPostTextWithMentions($preview, $db);
        
        // Links para o tópico completo
        $html .= '<div class="thread_links">';
        $html .= '[<a href="mod.php?post_id=' . $postId . '">Ver tópico completo</a>] ';
        $html .= '[<a href="mod.php?post_id=' . $postId . '#bottom">Responder</a>]';
        $html .= '</div>';
    } else {
        // No tópico individual, mostrar mensagem completa
        $html .= formatPostTextWithMentions($message, $db);
    }
    
    $html .= '</div>';
    $html .= '</div>'; // Fecha .post.op
    
    // No índice, mostrar algumas respostas recentes
    if ($isIndex) {
        $stmt = $db->prepare('SELECT * FROM posts WHERE parent_id = ? AND deleted = 0 AND is_reply = 1 AND approved = 1 ORDER BY created_at DESC LIMIT ' . MAIN_REPLIES_SHOWN);
        $stmt->execute([$postId]);
        $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($replies)) {
            $html .= '<div class="replies_preview">';
            foreach (array_reverse($replies) as $reply) {
                $html .= renderReply(
                    $reply['id'],
                    $reply['message'],
                    $reply['media'] ?? null,
                    $reply['thumb'] ?? null,
                    $reply['name'] ?? 'Anônimo',
                    $reply['created_at'],
                    $postId,
                    false,
                    ($reply['spoilered'] ?? 0) == 1,
                    $reply['capcode'] ?? null,
                    false
                );
            }
            $html .= '</div>';
        }
    }
    
    $html .= '</div>'; // Fecha .thread
    
    return $html;
}

/* =========================
   FUNÇÕES DE NOTÍCIAS
========================= */
function formatNewsDate(string $datetime): string {
    $timestamp = strtotime($datetime);
    
    // Dias da semana em português
    $days = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $dayOfWeek = $days[date('w', $timestamp)];
    
    return date('Y/m/d', $timestamp) . '<wbr>(' . $dayOfWeek . ')<wbr>' . date('H:i:s', $timestamp);
}

// ==================== FIXED renderNewsHTML FUNCTION - Don't escape title ====================
function renderNewsHTML(array $news): string {
    global $newsUploadsDir;
    
    $html = '<div class="news-item" id="news-' . $news['id'] . '">';
    $html .= '<div class="news-header">';
    // FIXED: Don't escape the title to preserve special characters like >
    $html .= '<div class="news-title">' . $news['title'] . '</div>';
    $html .= '<div class="news-meta">';
    $html .= 'Por <span class="postername">' . htmlspecialchars($news['author']) . '</span> | ';
    $html .= 'Publicado em: ' . formatNewsDate($news['created_at']);
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '<div class="news-content">';
    
    // Adicionar imagem diretamente se existir
    if (!empty($news['media']) && file_exists($news['media'])) {
        // CORREÇÃO: Agora as imagens estão em ../news/uploads/
        // A página de notícias está em ../news/index.html, então o caminho relativo é apenas "uploads/"
        $relativePath = 'uploads/' . basename($news['media']);
        $html .= '<div style="margin: 15px 0; text-align: center;">';
        $html .= '<img src="' . htmlspecialchars($relativePath) . '" alt="Imagem da notícia" style="max-width: 100%; max-height: 500px; border: 1px solid #ccc;" />';
        $html .= '</div>';
    }
    
    // Usar a função formatPostText fixada para notícias
    $html .= formatPostText($news['content']);
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

function generateNewsPages(): void {
    global $db, $newsDir, $newsUploadsDir;
    
    // Obter todas as notícias ativas ordenadas por data (mais recente primeiro)
    $stmt = $db->prepare('SELECT * FROM news WHERE is_active = 1 ORDER BY created_at DESC');
    $stmt->execute();
    $allNews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Página única de notícias (todas em uma página)
    $newsContent = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notícias</title>
    <style>
        body {
            background-color: #eef2ff;
            font-family: arial,helvetica,sans-serif;
            font-size: 10pt;
            margin: 20px;
            padding: 0;
        }
        .news-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #f0e0d6;
            border: 1px solid #d9bfb7;
            padding: 20px;
        }
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #af0a0f;
        }
        .page-header h1 {
            color: #af0a0f;
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        .page-header p {
            color: #707070;
            margin: 0;
        }
        .news-item {
            background-color: white;
            border: 1px solid #b7c5d9;
            margin-bottom: 25px;
            padding: 20px;
        }
        .news-header {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #d9bfb7;
        }
        .news-title {
            color: #af0a0f;
            margin: 0 0 5px 0;
            font-size: 16pt;
            font-weight: bold;
        }
        .news-meta {
            color: #707070;
            font-size: 10pt;
        }
        .postername {
            font-weight: bold;
            color: #117743;
        }
        .news-content {
            line-height: 1.6;
        }
        .back-link {
            text-align: center;
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #d9bfb7;
        }
        .back-link a {
            color: #34345c;
            text-decoration: none;
            font-weight: bold;
            padding: 8px 15px;
            border: 1px solid #b7c5d9;
            background-color: #eef2ff;
        }
        .back-link a:hover {
            background-color: #e0e8ff;
            text-decoration: none;
        }
        .greentext {
            color: #789922;
        }
        .redtext {
            color: #ff0000;
            font-weight: bold;
        }
        .spoiler-text {
            color: #000;
            background-color: #000;
            cursor: pointer;
        }
        .spoiler-text.revealed {
            color: #fff;
            background-color: #333;
        }
        .post_ref {
            color: #34345c;
            text-decoration: none;
        }
        .post_ref:hover {
            text-decoration: underline;
        }
        /* Estilos para notícias sem conteúdo */
        .empty-news {
            text-align: center;
            color: #707070;
            font-style: italic;
            padding: 40px 20px;
            background-color: white;
            border: 1px dashed #b7c5d9;
        }
        /* Estilos para imagens */
        .news-item img {
            display: block;
            margin: 15px auto;
            max-width: 100%;
            max-height: 500px;
            border: 1px solid #ccc;
        }
    </style>
</head>
<body>
    <div class="news-container">
        <div class="page-header">
            <h1>Notícias</h1>
            <p>Últimas atualizações e anúncios do fórum</p>
        </div>';
    
    if (empty($allNews)) {
        $newsContent .= '
        <div class="empty-news">
            <p>Nenhuma notícia publicada ainda.</p>
            <p>Volte mais tarde!</p>
        </div>';
    } else {
        foreach ($allNews as $news) {
            $newsContent .= renderNewsHTML($news);
        }
    }
    
    $newsContent .= '
        <div class="back-link">
            <a href="/b/index.html">← Voltar para o índice principal</a>
        </div>
    </div>
</body>
</html>';
    
    // Salvar página única de notícias
    file_put_contents($newsDir . 'index.html', $newsContent);
    
    // Remover arquivos individuais antigos de notícias
    $files = glob($newsDir . 'news-*.html');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

// =========================
// FUNÇÕES AUXILIARES
// =========================

function getReplyCount(PDO $db, int $post_id): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE parent_id = ? AND deleted = 0 AND is_reply = 1 AND approved = 1');
    $stmt->execute([$post_id]);
    return (int)$stmt->fetchColumn();
}

function formatTime(string $time): array {
    $timestamp = strtotime($time);
    
    // Formatar data em português brasileiro
    $days = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $dayOfWeek = $days[date('w', $timestamp)];
    
    $formatted = date('d/m/y', $timestamp) . ' (' . $dayOfWeek . ') ' . date('H:i:s', $timestamp);
    $datetime = date('Y-m-d\TH:i:s', $timestamp) . '-03:00';
    return ['formatted' => $formatted, 'datetime' => $datetime];
}

function formatName(string $raw, ?string $capcode = null): string {
    // Se tiver um tripcode
    if (str_contains($raw, '#')) {
        [$name, $trip] = explode('#', $raw, 2);
        $name = trim($name) ?: 'Anônimo';
        
        $trip = mb_convert_encoding($trip, 'SJIS', 'UTF-8');
        $salt = substr($trip . 'H.', 1, 2);
        $salt = preg_replace('/[^\.-z]/', '.', $salt);
        $salt = strtr($salt, ':;<=>?@[\\]^_`', 'ABCDEFGabcdef');
        
        $hash = crypt($trip, $salt);
        $tripcode = substr($hash, -10);
        
        $html = htmlspecialchars($name) . ' <span class="trip">!' . htmlspecialchars($tripcode) . '</span>';
    } else {
        $name = trim($raw) ?: 'Anônimo';
        $html = htmlspecialchars($name);
    }
    
    // Adicionar badge de capcode se presente
    if ($capcode) {
        $capcode = strtolower(trim($capcode));
        switch ($capcode) {
            case 'admin':
                $html = '<span class="admin-name">' . $html . ' <span class="admin-badge">@admin</span></span>';
                break;
            case 'mod':
                $html = '<span class="mod-name">' . $html . ' <span class="mod-badge">@mod</span></span>';
                break;
            case 'dev':
                $html = '<span class="dev-name">' . $html . ' <span class="dev-badge">@dev</span></span>';
                break;
        }
    }
    
    return $html;
}

// =========================
// PAGINAÇÃO E SEÇÃO ATUAL
// =========================
$section = $_GET['section'] ?? 'posts';

// Contar posts pendentes (para sistema de aprovação)
$pendingPosts = 0;
$pendingReplies = 0;
$totalPending = 0;

// Verificar se a tabela tem coluna approved
$checkApproved = $db->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_ASSOC);
$hasApproved = false;
foreach ($checkApproved as $col) {
    if ($col['name'] === 'approved') {
        $hasApproved = true;
        break;
    }
}

if ($hasApproved) {
    $pendingPosts = (int)$db->query('SELECT COUNT(*) FROM posts WHERE approved = 0 AND deleted = 0 AND is_reply = 0')->fetchColumn();
    $pendingReplies = (int)$db->query('SELECT COUNT(*) FROM posts WHERE approved = 0 AND deleted = 0 AND is_reply = 1')->fetchColumn();
    $totalPending = $pendingPosts + $pendingReplies;
}

// Paginação para posts
$totalPosts = (int)$db->query('SELECT COUNT(*) FROM posts WHERE deleted = 0 AND is_reply = 0')->fetchColumn();
$totalPages = max(1, (int)ceil($totalPosts / POSTS_PER_PAGE));
$page = max(1, (int)filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * POSTS_PER_PAGE;

$post_id = filter_input(INPUT_GET, 'post_id', FILTER_VALIDATE_INT) ?: null;

// Se tiver um hash na URL, extrair o ID do post para redirecionamento de menções
if (isset($_SERVER['REQUEST_URI']) && preg_match('/#post-(\d+)/', $_SERVER['REQUEST_URI'], $matches)) {
    $target_id = $matches[1];
    // Não precisamos redirecionar, apenas destacar via JavaScript
}

$active_page = $post_id ? 'thread' : 'index';

// Obter notícias para exibição
try {
    $newsStmt = $db->prepare('SELECT * FROM news ORDER BY created_at DESC LIMIT 10');
    $newsStmt->execute();
    $allNews = $newsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erro ao buscar notícias: ' . $e->getMessage());
    $allNews = [];
}

// Obter filtros de palavras
$wordFilters = loadWordFilters();

// Limpar buffer de saída antes da saída final
ob_end_clean();
?>

<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo BOARD_TITLE; ?> - Moderação</title>
<style>
    body {
        background-color: #eef2ff;
        font-family: arial,helvetica,sans-serif;
        font-size: 10pt;
        margin: 0;
        padding: 0;
    }
    header {
        text-align: center;
        padding: 10px;
        background-color: #f0e0d6;
        border-bottom: 1px solid #d9bfb7;
    }
    h1 {
        color: #af0a0f;
        font-size: 24px;
        margin: 5px 0;
    }
    .subtitle {
        color: #707070;
        font-size: 12px;
    }
    .mod-notice {
        background-color: #ffcccc;
        border: 1px solid #ff0000;
        padding: 5px;
        text-align: center;
        font-weight: bold;
        color: #ff0000;
    }
    .banner {
        background-color: #f0e0d6;
        border: 1px solid #d9bfb7;
        padding: 5px;
        margin: 10px;
        font-weight: bold;
    }
    .error {
        color: #ff0000;
        font-weight: bold;
        padding: 10px;
        background-color: #fff0f0;
        border: 1px solid #ffcccc;
        margin: 10px;
    }
    .success {
        color: #008000;
        font-weight: bold;
        padding: 10px;
        background-color: #f0fff0;
        border: 1px solid #ccffcc;
        margin: 10px;
    }
    table {
        background-color: #f0e0d6;
        border: 1px solid #d9bfb7;
        margin: 10px auto;
        padding: 10px;
    }
    th {
        text-align: right;
        padding-right: 5px;
        vertical-align: top;
    }
    td {
        padding: 2px;
    }
    input[type="text"], textarea {
        border: 1px solid #b7c5d9;
        font-family: arial,helvetica,sans-serif;
        font-size: 10pt;
    }
    input[type="submit"] {
        background-color: #34345c;
        color: white;
        border: none;
        padding: 5px 10px;
        cursor: pointer;
    }
    input[type="submit"]:hover {
        background-color: #af0a0f;
    }
    .thread {
        background-color: white;
        border: 1px solid #b7c5d9;
        margin: 10px;
        padding: 10px;
    }
    .post.op {
        background-color: #f0e0d6;
        padding: 5px;
        border-bottom: 1px solid #d9bfb7;
    }
    .post.reply {
        padding: 5px;
        border-bottom: 1px dotted #d9bfb7;
    }
    .intro {
        margin: 0;
        padding: 2px 0;
        font-size: 11px;
    }
    .subject {
        font-weight: bold;
        color: #0f0c5d;
    }
    .admin-name {
        font-weight: bold;
        color: #0055FF !important;
    }
    .admin-badge {
        background-color: #0055FF;
        color: white;
        padding: 1px 4px;
        border-radius: 3px;
        font-size: 9px;
        font-weight: bold;
        margin-left: 2px;
    }
    .mod-name {
        font-weight: bold;
        color: #FF5500 !important;
    }
    .mod-badge {
        background-color: #FF5500;
        color: white;
        padding: 1px 4px;
        border-radius: 3px;
        font-size: 9px;
        font-weight: bold;
        margin-left: 2px;
    }
    .dev-name {
        font-weight: bold;
        color: #00AA00 !important;
    }
    .dev-badge {
        background-color: #00AA00;
        color: white;
        padding: 1px 4px;
        border-radius: 3px;
        font-size: 9px;
        font-weight: bold;
        margin-left: 2px;
    }
    .name {
        font-weight: bold;
        color: #117743;
    }
    .trip {
        color: #228854;
        font-weight: bold;
    }
    .post_no {
        color: #34345c;
        text-decoration: none;
    }
    .post_no:hover {
        text-decoration: underline;
    }
    .body {
        margin: 5px 0;
        line-height: 1.3;
    }
    .greentext {
        color: #789922;
    }
    .redtext {
        color: #ff0000;
        font-weight: bold;
    }
    .spoiler-text {
        color: #000;
        background-color: #000;
        cursor: pointer;
    }
    .spoiler-text.revealed {
        color: #fff;
        background-color: #333;
    }
    .file {
        margin: 5px 0;
    }
    .fileinfo {
        font-size: 10px;
        margin: 2px 0;
    }
    .fileinfo a {
        color: #34345c;
        text-decoration: none;
    }
    .fileinfo a:hover {
        text-decoration: underline;
    }
    .unimportant {
        color: #707070;
    }
    .pages {
        text-align: center;
        margin: 20px;
        font-size: 12px;
    }
    .pages a {
        color: #34345c;
        text-decoration: none;
        padding: 2px 5px;
    }
    .pages a.selected {
        font-weight: bold;
        color: #af0a0f;
    }
    .pages a:hover {
        text-decoration: underline;
    }
    footer {
        text-align: center;
        font-size: 10px;
        color: #707070;
        margin: 20px;
        padding-top: 10px;
        border-top: 1px solid #b7c5d9;
    }
    .logout {
        float: right;
        margin-right: 20px;
        font-size: 11px;
    }
    .logout a {
        color: #af0a0f;
        text-decoration: none;
    }
    .logout a:hover {
        text-decoration: underline;
    }
    .sticky-post {
        background-color: #fff8dc !important;
        border-left: 3px solid #ffd700 !important;
    }
    .locked-post {
        background-color: #f8f8f8 !important;
        border-left: 3px solid #ccc !important;
    }
    .pending-post {
        background-color: #fff3cd !important;
        border-left: 3px solid #ffc107 !important;
    }
    .nav-tabs {
        background-color: #f0e0d6;
        border-bottom: 1px solid #d9bfb7;
        padding: 10px;
        text-align: center;
    }
    .nav-tabs a {
        color: #34345c;
        text-decoration: none;
        padding: 8px 15px;
        margin: 0 5px;
        border: 1px solid #d9bfb7;
        background-color: #e0d0c6;
        position: relative;
    }
    .nav-tabs a.active {
        background-color: #34345c;
        color: white;
        border-color: #34345c;
    }
    .nav-tabs a:hover:not(.active) {
        background-color: #d9bfb7;
    }
    .pending-count {
        background-color: #ff0000;
        color: white;
        border-radius: 10px;
        padding: 2px 6px;
        font-size: 9px;
        font-weight: bold;
        position: absolute;
        top: -5px;
        right: -5px;
    }
    .settings-container {
        max-width: 800px;
        margin: 20px auto;
        background-color: #f0e0d6;
        border: 1px solid #d9bfb7;
        padding: 20px;
    }
    .settings-container h2 {
        color: #af0a0f;
        margin-top: 0;
        border-bottom: 1px solid #d9bfb7;
        padding-bottom: 10px;
    }
    .settings-form {
        margin-top: 20px;
    }
    .setting-item {
        margin-bottom: 20px;
        padding: 15px;
        background-color: white;
        border: 1px solid #b7c5d9;
    }
    .setting-item h3 {
        color: #34345c;
        margin: 0 0 10px 0;
    }
    .setting-description {
        color: #707070;
        font-size: 9pt;
        margin-bottom: 10px;
    }
    .setting-control {
        margin: 10px 0;
    }
    .setting-control label {
        font-weight: bold;
        color: #34345c;
        margin-right: 10px;
    }
    .status-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: bold;
        margin-left: 10px;
    }
    .status-enabled {
        background-color: #4CAF50;
        color: white;
    }
    .status-disabled {
        background-color: #f44336;
        color: white;
    }
    .save-button {
        background-color: #4CAF50;
        color: white;
        border: none;
        padding: 10px 20px;
        cursor: pointer;
        font-size: 12pt;
        border-radius: 3px;
    }
    .save-button:hover {
        background-color: #45a049;
    }
    .news-list {
        margin: 20px;
        background-color: #f0e0d6;
        padding: 15px;
        border: 1px solid #d9bfb7;
    }
    .news-item {
        background-color: white;
        border: 1px solid #b7c5d9;
        margin-bottom: 10px;
        padding: 10px;
    }
    .news-item .title {
        font-weight: bold;
        color: #0f0c5d;
        font-size: 12pt;
        margin-bottom: 5px;
    }
    .news-item .meta {
        color: #707070;
        font-size: 9pt;
        margin-bottom: 8px;
    }
    .news-item .actions {
        margin-top: 10px;
        text-align: right;
    }
    .news-item .actions a {
        color: #34345c;
        text-decoration: none;
        margin-left: 10px;
        font-size: 9pt;
    }
    .news-item .actions a:hover {
        text-decoration: underline;
    }
    .news-item .preview {
        color: #505050;
        line-height: 1.4;
        margin-top: 5px;
    }
    .news-form-container {
        max-width: 800px;
        margin: 20px auto;
        background-color: #f0e0d6;
        border: 1px solid #d9bfb7;
        padding: 20px;
    }
    .news-form-container h2 {
        color: #af0a0f;
        margin-top: 0;
        border-bottom: 1px solid #d9bfb7;
        padding-bottom: 10px;
    }
    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #34345c;
    }
    .form-group input[type="text"],
    .form-group textarea {
        width: 100%;
        padding: 8px;
        box-sizing: border-box;
    }
    .form-group textarea {
        height: 200px;
        resize: vertical;
    }
    .form-actions {
        text-align: right;
        margin-top: 20px;
    }
    
    /* Estilos para o filtro de palavras */
    .filters-container {
        max-width: 800px;
        margin: 20px auto;
        background-color: #f0e0d6;
        border: 1px solid #d9bfb7;
        padding: 20px;
    }
    .filters-container h2 {
        color: #af0a0f;
        margin-top: 0;
        border-bottom: 1px solid #d9bfb7;
        padding-bottom: 10px;
    }
    .filter-section {
        margin-bottom: 30px;
    }
    .filter-section h3 {
        color: #34345c;
        margin: 10px 0;
    }
    .filter-list {
        background-color: white;
        border: 1px solid #b7c5d9;
        padding: 10px;
        max-height: 300px;
        overflow-y: auto;
    }
    .filter-item {
        padding: 5px;
        border-bottom: 1px dotted #d9bfb7;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .filter-item:hover {
        background-color: #f0e0d6;
    }
    .filter-word {
        font-family: monospace;
        font-size: 11pt;
        color: #af0a0f;
    }
    .filter-remove {
        color: #ff0000;
        text-decoration: none;
        font-size: 9pt;
        padding: 2px 5px;
    }
    .filter-remove:hover {
        text-decoration: underline;
    }
    .filter-add-form {
        margin-top: 15px;
        padding: 15px;
        background-color: #e0d0c6;
        border: 1px solid #d9bfb7;
    }
    .filter-add-form input[type="text"] {
        width: 300px;
        padding: 5px;
        margin-right: 10px;
    }
    .filter-add-form select {
        padding: 5px;
        margin-right: 10px;
    }

    /* ===== ESTILOS PARA MENÇÕES ===== */
    .mention-link {
        color: #0055FF;
        text-decoration: none;
        font-weight: bold;
        background-color: #e6f0ff;
        padding: 1px 3px;
        border-radius: 3px;
    }

    .mention-link:hover {
        text-decoration: underline;
        background-color: #cce0ff;
    }

    .mention-link.mention-deleted {
        color: #999;
        background-color: #f0f0f0;
        cursor: not-allowed;
    }

    .mention-badge-container {
        display: inline-block;
        margin-left: 5px;
    }

    .mention-badge {
        display: inline-block;
        background-color: #4CAF50;
        color: white;
        font-size: 10px;
        padding: 1px 5px;
        border-radius: 10px;
        margin: 0 2px;
        text-decoration: none;
    }

    .mention-badge:hover {
        background-color: #45a049;
        text-decoration: none;
    }

    /* Destaque para post mencionado */
    .highlight-post {
        animation: highlight-pulse 2s ease-in-out;
        border-left: 4px solid #FF5500 !important;
    }

    @keyframes highlight-pulse {
        0% { background-color: transparent; }
        30% { background-color: #fff3cd; }
        70% { background-color: #fff3cd; }
        100% { background-color: transparent; }
    }
</style>
<script>
const THUMB_MAX_WIDTH = 250;
const THUMB_MAX_HEIGHT = 250;

document.documentElement.className = document.documentElement.className.replace(/\bno-js\b/, '') + ' js';

let currentDeletePostId = null;
let currentDeleteIsReply = false;
let currentDeleteThreadId = null;

// ===== FUNÇÃO PARA DESTACAR POST MENCIONADO =====
function highlightPost(postId) {
    var postElement = document.getElementById('post-' + postId);
    if (postElement) {
        postElement.classList.add('highlight-post');
        postElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(function() {
            postElement.classList.remove('highlight-post');
        }, 2000);
    }
    return false;
}

document.addEventListener('DOMContentLoaded', function() {
    // Verificar se há um hash na URL para destacar um post
    if (window.location.hash) {
        var hash = window.location.hash;
        if (hash.startsWith('#post-')) {
            var postId = hash.substring(6);
            setTimeout(function() {
                highlightPost(postId);
            }, 500);
        }
    }

    var fileInput = document.getElementById('upload_file');
    var spoilerRow = document.getElementById('spoiler-row');
    var spoilerCheckbox = document.getElementById('spoiler-checkbox');
    
    if (fileInput && spoilerRow && spoilerCheckbox) {
        function toggleSpoilerRow() {
            if (fileInput.files.length > 0) {
                spoilerRow.style.display = '';
                spoilerCheckbox.disabled = false;
            } else {
                spoilerRow.style.display = 'none';
                spoilerCheckbox.checked = false;
                spoilerCheckbox.disabled = true;
            }
        }
        
        fileInput.addEventListener('change', toggleSpoilerRow);
        toggleSpoilerRow();
    }
});

function showDeleteForm(postId, isReply, threadId) {
    if (confirm('Tem certeza que deseja excluir este post?')) {
        window.location.href = 'mod.php?action=' + (isReply ? 'delete_reply' : 'delete') + '&id=' + postId + (threadId ? '&post_id=' + threadId : '') + '&csrf=<?php echo urlencode($csrf_token); ?>';
    }
}
</script>
</head>
<body>

<header>
    <div class="logout">
        Logado como <span class="admin-name"><?php echo MOD_ADMIN_NAME; ?> <span class="admin-badge">@admin</span></span> | [<a href="mod.php?action=logout">Sair</a>]
    </div>
    <h1><?php echo BOARD_TITLE; ?> - Moderação</h1>
    <div class="subtitle"><?php echo BOARD_SUBTITLE; ?></div>
    <div class="mod-notice">MODO MODERAÇÃO ATIVO</div>
</header>

<div class="nav-tabs">
    <a href="mod.php?section=posts" class="<?php echo $section === 'posts' ? 'active' : ''; ?>">Posts</a>
    <?php if ($hasApproved && $totalPending > 0): ?>
    <a href="mod.php?section=pending" class="<?php echo $section === 'pending' ? 'active' : ''; ?>">
        Pendentes
        <span class="pending-count"><?php echo $totalPending; ?></span>
    </a>
    <?php endif; ?>
    <a href="mod.php?section=news" class="<?php echo $section === 'news' ? 'active' : ''; ?>">Notícias</a>
    <a href="mod.php?section=filters" class="<?php echo $section === 'filters' ? 'active' : ''; ?>">Filtro de Palavras</a>
    <a href="mod.php?section=settings" class="<?php echo $section === 'settings' ? 'active' : ''; ?>">Configurações</a>
</div>

<?php if (isset($_GET['action']) && $_GET['action'] === 'logout'): ?>
    <?php
    setcookie(MANAGE_COOKIE, '', time() - 3600, "/");
    header('Location: mod.php');
    exit;
    ?>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
    <p class="error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></p>
<?php endif; ?>

<?php if (!empty($_SESSION['success'])): ?>
    <p class="success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></p>
<?php endif; ?>

<?php if ($section === 'settings'): ?>
    <div class="settings-container">
        <h2>Configurações do Sistema</h2>
        
        <form method="POST" action="mod.php" class="settings-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="save_settings" value="1">
            
            <div class="setting-item">
                <h3>Sistema de Aprovação de Posts</h3>
                <div class="setting-description">
                    Quando ativado, todos os novos posts de usuários comuns (não administradores) precisarão ser aprovados por um moderador antes de aparecerem no fórum. 
                    Quando desativado, os posts aparecem imediatamente sem necessidade de aprovação.
                </div>
                <div class="setting-control">
                    <label>
                        <input type="radio" name="approval_system" value="1" <?php echo $approval_system === '1' ? 'checked' : ''; ?>>
                        <strong>Ativado</strong> - Posts precisam de aprovação
                    </label>
                    <span class="status-badge <?php echo $approval_system === '1' ? 'status-enabled' : ''; ?>">
                        <?php echo $approval_system === '1' ? '✓ Ativo' : ''; ?>
                    </span>
                </div>
                <div class="setting-control">
                    <label>
                        <input type="radio" name="approval_system" value="0" <?php echo $approval_system === '0' ? 'checked' : ''; ?>>
                        <strong>Desativado</strong> - Posts aparecem imediatamente
                    </label>
                    <span class="status-badge <?php echo $approval_system === '0' ? 'status-disabled' : ''; ?>">
                        <?php echo $approval_system === '0' ? '✗ Inativo' : ''; ?>
                    </span>
                </div>
            </div>
            
            <div class="setting-item">
                <h3>Modo Apenas Leitura</h3>
                <div class="setting-description">
                    Quando ativado, os formulários de postagem no index.php ficarão ocultos, impedindo que usuários comuns criem novos tópicos ou respostas. 
                    Administradores ainda podem postar normalmente pelo painel de moderação.
                </div>
                <div class="setting-control">
                    <label>
                        <input type="radio" name="readonly_mode" value="1" <?php echo $readonly_mode === '1' ? 'checked' : ''; ?>>
                        <strong>Ativado</strong> - Usuários não podem postar
                    </label>
                    <span class="status-badge <?php echo $readonly_mode === '1' ? 'status-enabled' : ''; ?>">
                        <?php echo $readonly_mode === '1' ? '✓ Ativo' : ''; ?>
                    </span>
                </div>
                <div class="setting-control">
                    <label>
                        <input type="radio" name="readonly_mode" value="0" <?php echo $readonly_mode === '0' ? 'checked' : ''; ?>>
                        <strong>Desativado</strong> - Usuários podem postar normalmente
                    </label>
                    <span class="status-badge <?php echo $readonly_mode === '0' ? 'status-disabled' : ''; ?>">
                        <?php echo $readonly_mode === '0' ? '✗ Inativo' : ''; ?>
                    </span>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button type="submit" class="save-button">Salvar Configurações</button>
            </div>
        </form>
        
        <div style="margin-top: 30px; padding: 15px; background-color: #e0d0c6; border: 1px solid #d9bfb7;">
            <h3 style="color: #34345c; margin: 0 0 10px 0;">Informações</h3>
            <p style="margin: 0; color: #505050;">
                <strong>Sistema de aprovação:</strong> 
                <?php if ($approval_system === '1'): ?>
                    <span style="color: #4CAF50; font-weight: bold;">ATIVADO</span> - Posts precisam de aprovação
                <?php else: ?>
                    <span style="color: #f44336; font-weight: bold;">DESATIVADO</span> - Posts aparecem imediatamente
                <?php endif; ?>
            </p>
            <p style="margin: 10px 0 0 0; color: #505050;">
                <strong>Modo apenas leitura:</strong> 
                <?php if ($readonly_mode === '1'): ?>
                    <span style="color: #4CAF50; font-weight: bold;">ATIVADO</span> - Usuários não podem postar
                <?php else: ?>
                    <span style="color: #f44336; font-weight: bold;">DESATIVADO</span> - Usuários podem postar normalmente
                <?php endif; ?>
            </p>
            <?php if ($hasApproved): ?>
            <p style="margin: 10px 0 0 0; color: #505050;">
                <strong>Posts pendentes atualmente:</strong> <?php echo $totalPending; ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($section === 'filters'): ?>
    <div class="filters-container">
        <h2>Filtro de Palavras</h2>
        
        <div class="filter-section">
            <h3>Palavras Exatas (case insensitive)</h3>
            <p class="unimportant">Bloqueia posts que contenham esta palavra exata (ignora maiúsculas/minúsculas)</p>
            <div class="filter-list">
                <?php if (empty($wordFilters['exact'])): ?>
                    <p class="unimportant" style="text-align: center; padding: 10px;">Nenhuma palavra exata bloqueada</p>
                <?php else: ?>
                    <?php foreach ($wordFilters['exact'] as $word): ?>
                        <div class="filter-item">
                            <span class="filter-word"><?php echo htmlspecialchars($word); ?></span>
                            <form method="POST" action="mod.php" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="word" value="<?php echo htmlspecialchars($word); ?>">
                                <input type="hidden" name="filter_type" value="exact">
                                <button type="submit" name="remove_word_filter" class="filter-remove" onclick="return confirm('Remover esta palavra do filtro?')">[Remover]</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="filter-section">
            <h3>Palavras que Contém (case insensitive)</h3>
            <p class="unimportant">Bloqueia posts que contenham estas strings (ex: "discord.gg" bloqueia qualquer URL do Discord)</p>
            <div class="filter-list">
                <?php if (empty($wordFilters['contains'])): ?>
                    <p class="unimportant" style="text-align: center; padding: 10px;">Nenhuma palavra de conteúdo bloqueada</p>
                <?php else: ?>
                    <?php foreach ($wordFilters['contains'] as $word): ?>
                        <div class="filter-item">
                            <span class="filter-word"><?php echo htmlspecialchars($word); ?></span>
                            <form method="POST" action="mod.php" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="word" value="<?php echo htmlspecialchars($word); ?>">
                                <input type="hidden" name="filter_type" value="contains">
                                <button type="submit" name="remove_word_filter" class="filter-remove" onclick="return confirm('Remover esta palavra do filtro?')">[Remover]</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="filter-add-form">
            <h3>Adicionar Palavra ao Filtro</h3>
            <form method="POST" action="mod.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <input type="text" name="word" placeholder="Digite a palavra ou string" required>
                
                <select name="filter_type">
                    <option value="exact">Palavra Exata</option>
                    <option value="contains">Contém (ex: discord.gg)</option>
                </select>
                
                <input type="submit" name="add_word_filter" value="Adicionar">
            </form>
        </div>
    </div>

<?php elseif ($section === 'news'): ?>
    <?php if (isset($_GET['action']) && $_GET['action'] === 'new_news'): ?>
        <div class="news-form-container">
            <h2>Publicar Nova Notícia</h2>
            <form method="POST" action="mod.php" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="news_title">Título da Notícia:</label>
                    <input type="text" id="news_title" name="news_title" required>
                </div>
                
                <div class="form-group">
                    <label for="news_author">Autor:</label>
                    <input type="text" id="news_author" name="news_author" value="Admin">
                </div>
                
                <div class="form-group">
                    <label for="news_content">Conteúdo da Notícia:</label>
                    <textarea id="news_content" name="news_content" required></textarea>
                </div>
                
                <div class="form-group">
                    <label for="news_file">Arquivo de Mídia (opcional):</label>
                    <input type="file" id="news_file" name="news_file">
                    <div style="font-size: 9pt; color: #707070; margin-top: 5px;">
                        Tipos permitidos: JPG, PNG, GIF, WebP, WebM, MP4. Tamanho máximo: 15MB
                    </div>
                </div>
                
                <div class="form-actions">
                    <input type="submit" name="news_submit" value="Publicar Notícia">
                    <a href="mod.php?section=news" style="margin-left: 10px;">Cancelar</a>
                </div>
                
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            </form>
        </div>
    
    <?php elseif (isset($_GET['action']) && $_GET['action'] === 'edit_news' && isset($_GET['news_id'])): ?>
        <?php
        $news_id = sanitizeInt($_GET['news_id']);
        $stmt = $db->prepare('SELECT * FROM news WHERE id = ?');
        $stmt->execute([$news_id]);
        $news = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$news) {
            echo '<p class="error">Notícia não encontrada.</p>';
        } else {
        ?>
        <div class="news-form-container">
            <h2>Editar Notícia</h2>
            <form method="POST" action="mod.php" enctype="multipart/form-data">
                <input type="hidden" name="news_id" value="<?php echo $news['id']; ?>">
                
                <div class="form-group">
                    <label for="edit_news_title">Título da Notícia:</label>
                    <input type="text" id="edit_news_title" name="edit_news_title" value="<?php echo htmlspecialchars($news['title']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_news_author">Autor:</label>
                    <input type="text" id="edit_news_author" name="edit_news_author" value="<?php echo htmlspecialchars($news['author']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="edit_news_content">Conteúdo da Notícia:</label>
                    <textarea id="edit_news_content" name="edit_news_content" required><?php echo htmlspecialchars($news['content']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="edit_news_file">Substituir Arquivo de Mídia (opcional):</label>
                    <input type="file" id="edit_news_file" name="edit_news_file">
                    <div style="font-size: 9pt; color: #707070; margin-top: 5px;">
                        Deixe em branco para manter o arquivo atual. Tipos permitidos: JPG, PNG, GIF, WebP, WebM, MP4. Tamanho máximo: 15MB
                    </div>
                    <?php if (!empty($news['media'])): ?>
                        <div style="margin-top: 5px; font-size: 9pt;">
                            Arquivo atual: <?php echo htmlspecialchars(basename($news['media'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-actions">
                    <input type="submit" name="edit_news_submit" value="Atualizar Notícia">
                    <a href="mod.php?section=news" style="margin-left: 10px;">Cancelar</a>
                </div>
                
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            </form>
        </div>
        <?php } ?>
    
    <?php else: ?>
        <div class="news-list">
            <div style="text-align: right; margin-bottom: 15px;">
                <a href="mod.php?section=news&action=new_news" style="background-color: #34345c; color: white; padding: 8px 15px; text-decoration: none;">+ Nova Notícia</a>
            </div>
            
            <?php if (empty($allNews)): ?>
                <p style="text-align: center; color: #707070;">Nenhuma notícia publicada ainda.</p>
            <?php else: ?>
                <?php foreach ($allNews as $news): ?>
                    <div class="news-item">
                        <div class="title"><?php echo htmlspecialchars($news['title']); ?></div>
                        <div class="meta">
                            Por <?php echo htmlspecialchars($news['author']); ?> | 
                            Publicado em: <?php echo formatNewsDate($news['created_at']); ?>
                        </div>
                        <div class="preview">
                            <?php echo substr(strip_tags($news['content']), 0, 200); ?>...
                        </div>
                        <div class="actions">
                            <a href="mod.php?section=news&action=edit_news&news_id=<?php echo $news['id']; ?>">Editar</a>
                            <a href="mod.php?section=news&action=delete_news&news_id=<?php echo $news['id']; ?>&csrf=<?php echo urlencode($csrf_token); ?>" onclick="return confirm('Tem certeza que deseja excluir esta notícia?')">Excluir</a>
                            <a href="../news/index.html#news-<?php echo $news['id']; ?>" target="_blank">Ver</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php elseif ($section === 'pending' && $hasApproved): ?>
    <div class="banner">
        Posts Aguardando Aprovação (<?php echo $totalPending; ?>)
    </div>
    
    <?php
    // Buscar posts pendentes (tópicos)
    $pendingPostsStmt = $db->prepare('SELECT * FROM posts WHERE approved = 0 AND deleted = 0 AND is_reply = 0 ORDER BY created_at ASC');
    $pendingPostsStmt->execute();
    $pendingPosts = $pendingPostsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar respostas pendentes
    $pendingRepliesStmt = $db->prepare('SELECT * FROM posts WHERE approved = 0 AND deleted = 0 AND is_reply = 1 ORDER BY created_at ASC');
    $pendingRepliesStmt->execute();
    $pendingReplies = $pendingRepliesStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    
    <?php if (empty($pendingPosts) && empty($pendingReplies)): ?>
        <p style="text-align: center; color: #707070; margin: 50px;">Nenhum post pendente no momento.</p>
    <?php else: ?>
        
        <?php if (!empty($pendingPosts)): ?>
            <h3 style="margin-left: 20px;">Tópicos Novos</h3>
            <?php foreach ($pendingPosts as $post): ?>
                <?php
                echo renderPost(
                    $post['id'], 
                    $post['title'], 
                    $post['message'], 
                    $post['media'] ?? null, 
                    $post['thumb'] ?? null, 
                    $post['name'] ?? 'Anônimo', 
                    $post['created_at'], 
                    false, 
                    ($post['deleted'] ?? 0) == 1, 
                    ($post['spoilered'] ?? 0) == 1,
                    ($post['sticky'] ?? 0) == 1,
                    ($post['locked'] ?? 0) == 1,
                    $post['capcode'] ?? null,
                    true
                );
                ?>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (!empty($pendingReplies)): ?>
            <h3 style="margin-left: 20px; margin-top: 30px;">Respostas Novas</h3>
            <?php foreach ($pendingReplies as $reply): ?>
                <?php
                echo renderReply(
                    $reply['id'],
                    $reply['message'],
                    $reply['media'] ?? null,
                    $reply['thumb'] ?? null,
                    $reply['name'] ?? 'Anônimo',
                    $reply['created_at'],
                    $reply['parent_id'],
                    ($reply['deleted'] ?? 0) == 1,
                    ($reply['spoilered'] ?? 0) == 1,
                    $reply['capcode'] ?? null,
                    true
                );
                ?>
            <?php endforeach; ?>
        <?php endif; ?>
        
    <?php endif; ?>

<?php else: // Seção de posts ?>
    <?php if ($post_id): ?>
        <a name="top"></a>
        <div class="banner">Modo postagem: Resposta <a class="unimportant" href="mod.php">[Voltar]</a> <a class="unimportant" href="#bottom">[Ir para o final]</a></div>
    <?php endif; ?>

    <?php if ($post_id !== null && $post_id > 0): ?>
        <?php
        // CORREÇÃO: Buscar post na tabela unificada (is_reply = 0 para tópicos)
        $stmt = $db->prepare('SELECT * FROM posts WHERE id = ? AND deleted = 0 AND is_reply = 0');
        $stmt->execute([$post_id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$post) {
            handleError('Post não encontrado.', 404);
        }
        
        $is_locked = ($post['locked'] ?? 0) == 1;
        ?>
        
        <?php if (!$is_locked): ?>
        <form name="post" method="post" enctype="multipart/form-data" action="mod.php">
            <input type="hidden" name="thread" value="<?php echo $post_id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <table>
                <tr><th>Nome</th><td><input type="text" name="name" size="25" maxlength="35" value="admin"></td></tr>
                <tr><th>Comentário</th><td><textarea name="body" rows="5" cols="35" required></textarea></td></tr>
                <tr id="upload"><th>Arquivo</th><td><input type="file" name="file" id="upload_file"></td></tr>
                <tr id="spoiler-row" style="display: none;"><th>Spoiler</th><td><label><input type="checkbox" name="spoiler" id="spoiler-checkbox" disabled> Marcar imagem como spoiler</label></td></tr>
                <tr><td colspan="2"><input accesskey="s" type="submit" name="post" value="Nova Resposta" /></td></tr>
            </table>
        </form>
        <?php else: ?>
            <p class="banner">Este tópico está bloqueado. Nenhuma resposta pode ser postada.</p>
        <?php endif; ?>
        
        <hr>
        
        <?php 
        echo renderPost(
            $post['id'], 
            $post['title'], 
            $post['message'], 
            $post['media'] ?? null, 
            $post['thumb'] ?? null, 
            $post['name'] ?? 'Anônimo', 
            $post['created_at'], 
            false, 
            ($post['deleted'] ?? 0) == 1, 
            ($post['spoilered'] ?? 0) == 1,
            ($post['sticky'] ?? 0) == 1,
            ($post['locked'] ?? 0) == 1,
            $post['capcode'] ?? null,
            false
        ); 
        ?>
        
        <?php
        // CORREÇÃO: Buscar respostas na tabela unificada (apenas aprovadas)
        $replyStmt = $db->prepare('SELECT * FROM posts WHERE parent_id = ? AND deleted = 0 AND is_reply = 1 AND approved = 1 ORDER BY created_at ASC');
        $replyStmt->execute([$post_id]);
        
        while ($reply = $replyStmt->fetch(PDO::FETCH_ASSOC)) {
            echo renderReply(
                $reply['id'],
                $reply['message'],
                $reply['media'] ?? null,
                $reply['thumb'] ?? null,
                $reply['name'] ?? 'Anônimo',
                $reply['created_at'],
                $post_id,
                ($reply['deleted'] ?? 0) == 1,
                ($reply['spoilered'] ?? 0) == 1,
                $reply['capcode'] ?? null,
                false
            );
        }
        ?>
        
        <a name="bottom"></a>
        
    <?php else: ?>
        
        <form name="post" method="post" enctype="multipart/form-data" action="mod.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <table>
                <tr><th>Nome</th><td><input type="text" name="name" size="25" maxlength="35" value="admin"></td></tr>
                <tr><th>Tópico</th><td><input type="text" name="subject" size="25" maxlength="100" placeholder="Opcional"></td></tr>
                <tr><th>Post</th><td><textarea name="body" rows="5" cols="35" required></textarea></td></tr>
                <tr id="upload"><th>Arquivo</th><td><input type="file" name="file" id="upload_file"></td></tr>
                <tr id="spoiler-row" style="display: none;"><th>Spoiler</th><td><label><input type="checkbox" name="spoiler" id="spoiler-checkbox" disabled> Marcar imagem como spoiler</label></td></tr>
                <tr><td colspan="2"><input accesskey="s" type="submit" name="post" value="Novo Tópico" /></td></tr>
            </table>
        </form>
        
        <hr>
        
        <?php
        // CORREÇÃO: Buscar tópicos na tabela unificada (is_reply = 0) apenas aprovados
        $stmt = $db->prepare("SELECT * FROM posts WHERE deleted = 0 AND is_reply = 0 AND approved = 1 ORDER BY sticky DESC, updated_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([POSTS_PER_PAGE, $offset]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo renderPost(
                $row['id'], 
                $row['title'], 
                $row['message'], 
                $row['media'] ?? null, 
                $row['thumb'] ?? null, 
                $row['name'] ?? 'Anônimo', 
                $row['created_at'], 
                true, 
                ($row['deleted'] ?? 0) == 1, 
                ($row['spoilered'] ?? 0) == 1,
                ($row['sticky'] ?? 0) == 1,
                ($row['locked'] ?? 0) == 1,
                $row['capcode'] ?? null,
                false
            );
        }
        ?>
        
        <?php if ($totalPages > 1): ?>
        <div class="pages">
            <?php
            $prevText = $page > 1 ? '<a href="mod.php?page=' . ($page - 1) . '">Anterior</a>' : 'Anterior';
            $nextText = $page < $totalPages ? '<a href="mod.php?page=' . ($page + 1) . '">Próximo</a>' : 'Próximo';
            
            echo $prevText . ' [';
            for ($i = 1; $i <= $totalPages; $i++) {
                if ($i === $page) {
                    echo '<a class="selected">' . $i . '</a>';
                } else {
                    echo '<a href="mod.php?page=' . $i . '">' . $i . '</a>';
                }
            }
            echo '] ' . $nextText;
            ?>
        </div>
        <?php endif; ?>
        
    <?php endif; ?>
<?php endif; ?>

<br clear="all">
<footer>
<a href="https://github.com/peidorreiro/LaranjaEngine">LaranjaEngine</a>
</footer>
</body>
</html>
