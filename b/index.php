<?php
// =========================
// CONFIGURAÇÃO DE FUSO HORÁRIO DE SÃO PAULO
// =========================
date_default_timezone_set('America/Sao_Paulo');

// Configurar localidade para português brasileiro
setlocale(LC_TIME, 'pt_BR.utf-8', 'pt_BR', 'portuguese');

/* =========================
   CONFIGURAÇÕES
========================= */
const POSTS_PER_PAGE = 20;
const REPLIES_PER_PAGE = 1000;
const MAX_UPLOAD_SIZE = 15 * 1024 * 1024; // 15MB
const MAX_PREVIEW_CHARS = 500;
const MAIN_REPLIES_SHOWN = 5;
const MAX_REPLY_PREVIEW_CHARS = 500;
const ALLOWED_FILE_TYPES = [
    'image/jpeg','image/png','image/gif','image/webp',
    'video/webm','video/mp4'
];
const BOARD_TITLE = '/b/ - Aleatório';
const BOARD_SUBTITLE = '';
const BOARD_NAME = 'b';

/* =========================
   CONFIGURAÇÃO ANTI-SPAM GLOBAL (baseada em sessão)
   ========================= */
const ANTI_SPAM_TIME = 1; // Tempo em segundos entre posts (QUALQUER post - tópico novo ou resposta)

/* =========================
   CONFIGURAÇÃO DE PROCESSAMENTO DE IMAGEM E SEGURANÇA
========================= */
const IMAGE_QUALITY = 75;
const FULL_IMAGE_QUALITY = 85;
const THUMB_MAX_WIDTH = 250;
const THUMB_MAX_HEIGHT = 250;
const MAX_IMAGE_DIMENSIONS = 2500;
const USE_WEBP = true;
const GENERATE_THUMBNAILS = true;
const ENABLE_LAZY_LOADING = true;
const VIDEO_MAX_SIZE = '250x250';
const JPEG_QUALITY = 85;
const PNG_COMPRESSION = 6;

$uploadsDir = 'uploads/';
$thumbsDir = 'uploads/thumbs/';
$bannersDir = 'banners/';
$logsDir    = 'logs/';

$spoilerImagePath = __DIR__ . '/stylesheets/spoiler.png';
$webSpoilerImage = 'stylesheets/spoiler.png';

/* =========================
   FILTRO DE PALAVRAS - FUNÇÕES
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

/* =========================
   FUNÇÕES ANTI-SPAM GLOBAIS (baseadas em sessão)
========================= */

// Inicializar sistema anti-spam na sessão
function initAntiSpam(): void {
    if (!isset($_SESSION['last_post_time'])) {
        $_SESSION['last_post_time'] = 0;
    }
}

// Verificar se o usuário pode postar
function checkAntiSpam(): ?string {
    initAntiSpam();
    
    $current_time = time();
    $last_post = $_SESSION['last_post_time'];
    
    // Se já postou antes
    if ($last_post > 0) {
        $time_diff = $current_time - $last_post;
        if ($time_diff < ANTI_SPAM_TIME) {
            $wait_time = ANTI_SPAM_TIME - $time_diff;
            return "Aguarde mais $wait_time segundos antes de fazer um novo post.";
        }
    }
    
    return null; // Pode postar
}

// Registrar um post bem-sucedido
function registerAntiSpam(): void {
    initAntiSpam();
    $_SESSION['last_post_time'] = time();
}

// Função para mostrar o tempo restante (opcional - pode ser usado no template)
function getAntiSpamRemainingTime(): int {
    initAntiSpam();
    
    $current_time = time();
    $last_post = $_SESSION['last_post_time'];
    
    if ($last_post > 0) {
        $time_diff = $current_time - $last_post;
        if ($time_diff < ANTI_SPAM_TIME) {
            return ANTI_SPAM_TIME - $time_diff;
        }
    }
    
    return 0;
}

/* =========================
   SETUP
========================= */
ob_start();

// Tenta configurar log de erros, mas não trava se não conseguir
@ini_set('log_errors', '1');
@ini_set('error_log', $logsDir . 'php_errors.log');

session_start([
    'cookie_lifetime' => 86400,
    'cookie_httponly' => true,
    'cookie_secure'  => isset($_SERVER['HTTPS']),
    'cookie_samesite'=> 'Strict',
]);

if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// Inicializar anti-spam
initAntiSpam();

// Criar diretórios necessários
foreach ([$uploadsDir, $thumbsDir, $bannersDir, $logsDir] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Verificar apenas diretórios essenciais (uploads e thumbs)
foreach ([$uploadsDir, $thumbsDir] as $dir) {
    if (!is_writable($dir)) {
        die("Erro: O diretório $dir não é gravável.");
    }
}

// Garantir que o diretório de estilos existe
$stylesheetsDir = dirname($spoilerImagePath);
if (!is_dir($stylesheetsDir)) {
    @mkdir($stylesheetsDir, 0755, true);
}

// Verificar se a imagem de spoiler existe, se não, criar uma simples
if (!file_exists($spoilerImagePath)) {
    if (function_exists('imagecreatetruecolor')) {
        $img = @imagecreatetruecolor(250, 250);
        if ($img) {
            $black = @imagecolorallocate($img, 0, 0, 0);
            $white = @imagecolorallocate($img, 255, 255, 255);
            @imagefilledrectangle($img, 0, 0, 250, 250, $black);
            
            $text = "SPOILER";
            $x = (int)((250 - (imagefontwidth(5) * strlen($text))) / 2);
            $y = (int)((250 - imagefontheight(5)) / 2);
            @imagestring($img, 5, $x, $y, $text, $white);
            
            @imagepng($img, $spoilerImagePath);
            @chmod($spoilerImagePath, 0644);
            @imagedestroy($img);
        }
    }
}

/* =========================
   BANCO DE DADOS
========================= */
try {
    $dbPath = $uploadsDir . 'message_board.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec("PRAGMA timezone = '-03:00'");

    // Criar tabela unificada com campo approved
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
        password TEXT DEFAULT NULL,
        capcode TEXT DEFAULT NULL,
        is_reply INTEGER DEFAULT 0,
        parent_id INTEGER DEFAULT NULL,
        approved INTEGER DEFAULT 1,
        approved_at DATETIME,
        FOREIGN KEY(parent_id) REFERENCES posts(id) ON DELETE CASCADE
    )");

    $db->exec("
    CREATE TABLE IF NOT EXISTS replies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER,
        message TEXT,
        media TEXT,
        thumb TEXT,
        name TEXT,
        created_at DATETIME DEFAULT (datetime('now', 'localtime')),
        deleted INTEGER DEFAULT 0,
        postiphash TEXT,
        spoilered INTEGER DEFAULT 0,
        password TEXT DEFAULT NULL,
        capcode TEXT DEFAULT NULL,
        approved INTEGER DEFAULT 1,
        approved_at DATETIME,
        FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE
    )");

    // Criar tabela de configurações
    $db->exec("
    CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT,
        updated_at DATETIME DEFAULT (datetime('now', 'localtime'))
    )");

    // ===== NOVA TABELA PARA MENÇÕES =====
    $db->exec("
    CREATE TABLE IF NOT EXISTS mentions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_id INTEGER NOT NULL,
        target_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT (datetime('now', 'localtime')),
        FOREIGN KEY(source_id) REFERENCES posts(id) ON DELETE CASCADE,
        FOREIGN KEY(target_id) REFERENCES posts(id) ON DELETE CASCADE
    )");

    // Criar índices
    $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_parent ON posts(parent_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_is_reply ON posts(is_reply)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_sticky_updated ON posts(sticky DESC, updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_deleted_sticky_updated ON posts(deleted, sticky DESC, updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_updated_at ON posts(updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_sticky ON posts(sticky DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_posts_approved ON posts(approved)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_replies_approved ON replies(approved)');
    
    // Índices para menções
    $db->exec('CREATE INDEX IF NOT EXISTS idx_mentions_source ON mentions(source_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_mentions_target ON mentions(target_id)');
    
    // Migrar dados da tabela antiga 'replies' se existir
    $migration_check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='replies'")->fetch();
    if ($migration_check) {
        try {
            $db->beginTransaction();
            
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
                            (id, message, media, thumb, name, created_at, deleted, postiphash, spoilered, password, capcode, is_reply, parent_id, approved) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 1)");
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
                            $reply['password'] ?? null,
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
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            // Log do erro mas continua execução
            error_log("Erro na migração: " . $e->getMessage());
        }
    }
    
} catch (PDOException $e) {
    die("Erro ao conectar ao banco de dados: " . $e->getMessage());
}

/* =========================
   FUNÇÕES DE CONFIGURAÇÃO
========================= */

// Função para obter configuração
function getSetting($db, $key, $default = null) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['value'] : $default;
}

// Obter configurações atuais
$approval_system = getSetting($db, 'approval_system', '0');
$readonly_mode = getSetting($db, 'readonly_mode', '0');

// Determinar se posts precisam de aprovação
$requires_approval = ($approval_system === '1');

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
        // Verificar se o post alvo existe (em qualquer lugar, seja tópico ou resposta)
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
        JOIN posts p ON m.source_id = p.id
        WHERE m.target_id = ? AND p.deleted = 0
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
            $formattedLines[] = '<span class="greentext">' . htmlspecialchars($trimmedLine, ENT_QUOTES, 'UTF-8') . '</span>';
            continue;
        }
        
        $escapedLine = htmlspecialchars($trimmedLine, ENT_QUOTES, 'UTF-8');
        
        // Processar menções >>123
        $escapedLine = preg_replace_callback('/>>(\d+)/', function($matches) use ($db) {
            $target_id = $matches[1];
            $mentionLink = '<a href="#post-' . $target_id . '" class="mention-link" onclick="highlightPost(' . $target_id . '); return false;">>>' . $target_id . '</a>';
            
            // Se tiver acesso ao banco, podemos verificar se o post existe
            if ($db) {
                $check = $db->prepare("SELECT id FROM posts WHERE id = ? AND deleted = 0");
                $check->execute([$target_id]);
                if (!$check->fetch()) {
                    $mentionLink = '<span class="mention-link mention-deleted">>>' . $target_id . ' (deletado)</span>';
                }
            }
            
            return $mentionLink;
        }, $escapedLine);
        
        $processedLine = preg_replace('/==([^=\n]+?)==/u', '<span class="redtext">$1</span>', $escapedLine);
        $processedLine = preg_replace('/\*\*([^\*\n]+?)\*\*/u', '<span class="spoiler-text">$1</span>', $processedLine);
        
        $formattedLines[] = $processedLine;
    }
    
    return implode("<br>", $formattedLines);
}

/* =========================
   FUNÇÕES AUXILIARES
========================= */
function handleError($msg, $code = 400) {
    http_response_code($code);
    $_SESSION['error'] = $msg;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function getHashedFilename($originalName) {
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $hash = hash('sha256', $originalName . microtime() . random_bytes(16));
    return substr($hash, 0, 32) . ($ext ? ".$ext" : '');
}

function getReplyCount($db, $post_id) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE parent_id = ? AND deleted = 0 AND is_reply = 1 AND approved = 1');
    $stmt->execute([$post_id]);
    return (int)$stmt->fetchColumn();
}

function formatTime($time) {
    $timestamp = strtotime($time);
    
    $dias_semana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $dia_semana_num = date('w', $timestamp);
    $dia_semana = $dias_semana[$dia_semana_num] ?? '???';
    
    $data_formatada = date('d/m/y', $timestamp) . " ($dia_semana) " . date('H:i:s', $timestamp);
    $datetime = date('Y-m-d\TH:i:s', $timestamp) . '-03:00';
    
    return ['formatted' => $data_formatada, 'datetime' => $datetime];
}

function isAnimatedGif($filename) {
    if (!($fh = @fopen($filename, 'rb'))) {
        return false;
    }
    
    $count = 0;
    while (!feof($fh) && $count < 2) {
        $chunk = fread($fh, 1024 * 100);
        $count += preg_match_all('#\x00\x21\xF9\x04#', $chunk);
    }
    
    fclose($fh);
    return $count > 1;
}

function createImageThumbnail($source, $destination, $maxWidth, $maxHeight) {
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    
    $imageInfo = @getimagesize($source);
    if (!$imageInfo) return false;
    
    list($origW, $origH, $type) = $imageInfo;
    
    $ratio = min($maxWidth / $origW, $maxHeight / $origH, 1);
    $thumbW = (int)($origW * $ratio);
    $thumbH = (int)($origH * $ratio);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            $sourceImage = @imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = @imagecreatefrompng($source);
            break;
        case IMAGETYPE_GIF:
            $sourceImage = @imagecreatefromgif($source);
            break;
        case IMAGETYPE_WEBP:
            $sourceImage = @imagecreatefromwebp($source);
            break;
        default:
            return false;
    }
    
    if (!$sourceImage) return false;
    
    $thumbImage = @imagecreatetruecolor($thumbW, $thumbH);
    if (!$thumbImage) {
        @imagedestroy($sourceImage);
        return false;
    }
    
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        @imagealphablending($thumbImage, false);
        @imagesavealpha($thumbImage, true);
        $transparent = @imagecolorallocatealpha($thumbImage, 0, 0, 0, 127);
        @imagefill($thumbImage, 0, 0, $transparent);
    }
    
    @imagecopyresampled($thumbImage, $sourceImage, 0, 0, 0, 0, $thumbW, $thumbH, $origW, $origH);
    $success = @imagewebp($thumbImage, $destination, IMAGE_QUALITY);
    
    @imagedestroy($sourceImage);
    @imagedestroy($thumbImage);
    
    return $success;
}

function processAndOptimizeImage($tmp, $dir, $name, $createThumb = true) {
    global $thumbsDir;
    
    $hashedName = getHashedFilename($name);
    $originalExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    
    $isAnimatedGif = ($originalExt === 'gif' && isAnimatedGif($tmp));
    
    if (USE_WEBP && !$isAnimatedGif && function_exists('imagewebp')) {
        $outputExt = 'webp';
        $filename = pathinfo($hashedName, PATHINFO_FILENAME) . '.webp';
    } else {
        $filename = $hashedName;
        $outputExt = $originalExt;
    }
    
    $path = $dir . $filename;
    $thumbPath = '';
    
    if ($isAnimatedGif) {
        if (!@copy($tmp, $path)) {
            handleError('Falha ao salvar arquivo GIF.');
        }
        
        if ($createThumb && GENERATE_THUMBNAILS) {
            $thumbFilename = 'thumb_' . pathinfo($hashedName, PATHINFO_FILENAME) . '.webp';
            $thumbPath = $thumbsDir . $thumbFilename;
            createGifThumbnail($path, $thumbPath);
        }
    } else {
        $imageInfo = @getimagesize($tmp);
        if (!$imageInfo) {
            handleError('Arquivo de imagem inválido.');
        }
        
        list($origW, $origH, $type) = $imageInfo;
        
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = @imagecreatefromjpeg($tmp);
                break;
            case IMAGETYPE_PNG:
                $source = @imagecreatefrompng($tmp);
                break;
            case IMAGETYPE_GIF:
                $source = @imagecreatefromgif($tmp);
                break;
            case IMAGETYPE_WEBP:
                $source = @imagecreatefromwebp($tmp);
                break;
            default:
                handleError('Formato de imagem não suportado.');
        }
        
        if (!$source) {
            handleError('Falha ao processar imagem.');
        }
        
        $maxDim = MAX_IMAGE_DIMENSIONS;
        $ratio = min($maxDim / $origW, $maxDim / $origH, 1);
        $newW = (int)($origW * $ratio);
        $newH = (int)($origH * $ratio);
        
        if ($newW != $origW || $newH != $origH) {
            $resized = @imagecreatetruecolor($newW, $newH);
            if ($resized) {
                if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
                    @imagealphablending($resized, false);
                    @imagesavealpha($resized, true);
                    $transparent = @imagecolorallocatealpha($resized, 0, 0, 0, 127);
                    @imagefill($resized, 0, 0, $transparent);
                }
                
                @imagecopyresampled($resized, $source, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                @imagedestroy($source);
                $source = $resized;
            }
        }
        
        if ($outputExt === 'webp' && function_exists('imagewebp')) {
            @imagewebp($source, $path, FULL_IMAGE_QUALITY);
        } elseif ($outputExt === 'png' && function_exists('imagepng')) {
            @imagepng($source, $path, PNG_COMPRESSION);
        } elseif (($outputExt === 'jpg' || $outputExt === 'jpeg') && function_exists('imagejpeg')) {
            @imagejpeg($source, $path, JPEG_QUALITY);
        } elseif ($outputExt === 'gif' && function_exists('imagegif')) {
            @imagegif($source, $path);
        } else {
            @copy($tmp, $path);
        }
        
        @imagedestroy($source);
        
        if ($createThumb && GENERATE_THUMBNAILS && function_exists('imagewebp')) {
            $thumbFilename = 'thumb_' . pathinfo($hashedName, PATHINFO_FILENAME) . '.webp';
            $thumbPath = $thumbsDir . $thumbFilename;
            createImageThumbnail($path, $thumbPath, THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT);
        }
    }
    
    return [
        'main' => $path,
        'thumb' => $thumbPath
    ];
}

function createGifThumbnail($gifPath, $thumbPath) {
    if (function_exists('shell_exec')) {
        $rand_frame = rand(1, 100);
        $command = "ffmpeg -i " . escapeshellarg($gifPath) . 
                   " -vf 'select=gte(n\\," . $rand_frame . "),scale=" . THUMB_MAX_WIDTH . ":-1:flags=lanczos' " .
                   " -vframes 1 -f image2pipe -vcodec png - 2>/dev/null";
        
        $output = @shell_exec($command);
        
        if ($output) {
            $img = @imagecreatefromstring($output);
            if ($img) {
                $thumb = @imagecreatetruecolor(THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT);
                if ($thumb) {
                    $origW = @imagesx($img);
                    $origH = @imagesy($img);
                    $ratio = min(THUMB_MAX_WIDTH / $origW, THUMB_MAX_HEIGHT / $origH, 1);
                    $newW = (int)($origW * $ratio);
                    $newH = (int)($origH * $ratio);
                    $dstX = (int)((THUMB_MAX_WIDTH - $newW) / 2);
                    $dstY = (int)((THUMB_MAX_HEIGHT - $newH) / 2);
                    
                    @imagecopyresampled($thumb, $img, $dstX, $dstY, 0, 0, $newW, $newH, $origW, $origH);
                    @imagewebp($thumb, $thumbPath, IMAGE_QUALITY);
                    @imagedestroy($thumb);
                    @imagedestroy($img);
                    return true;
                }
                @imagedestroy($img);
            }
        }
    }
    
    if (function_exists('imagecreatefromgif') && function_exists('imagecreatetruecolor')) {
        $img = @imagecreatetruecolor(THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT);
        if (!$img) return false;
        
        $source = @imagecreatefromgif($gifPath);
        if ($source) {
            $origW = @imagesx($source);
            $origH = @imagesy($source);
            
            $ratio = min(THUMB_MAX_WIDTH / $origW, THUMB_MAX_HEIGHT / $origH, 1);
            $newW = (int)($origW * $ratio);
            $newH = (int)($origH * $ratio);
            $dstX = (int)((THUMB_MAX_WIDTH - $newW) / 2);
            $dstY = (int)((THUMB_MAX_HEIGHT - $newH) / 2);
            
            @imagecopyresampled($img, $source, $dstX, $dstY, 0, 0, $newW, $newH, $origW, $origH);
            @imagewebp($img, $thumbPath, IMAGE_QUALITY);
            
            @imagedestroy($source);
            @imagedestroy($img);
            return true;
        }
        @imagedestroy($img);
    }
    
    return false;
}

function getVideoDuration($videoPath) {
    if (function_exists('shell_exec')) {
        $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($videoPath) . " 2>/dev/null";
        $output = @shell_exec($command);
        if ($output !== null && $output !== '') {
            return (float)trim($output);
        }
    }
    return 30;
}

function createVideoThumbnail($videoPath, $thumbPath) {
    if (!function_exists('shell_exec')) {
        return false;
    }
    
    $duration = getVideoDuration($videoPath);
    $timestamp = max(1, rand(1, max(1, (int)$duration - 5)));
    
    $hours = floor($timestamp / 3600);
    $minutes = floor(($timestamp - ($hours * 3600)) / 60);
    $seconds = floor($timestamp % 60);
    $timeString = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    
    $command = "ffmpeg -ss " . escapeshellarg($timeString) . 
               " -i " . escapeshellarg($videoPath) . 
               " -vframes 1 -vf 'scale=" . THUMB_MAX_WIDTH . ":-1:force_original_aspect_ratio=increase' " .
               " -qscale:v 2 -f image2pipe -vcodec png - 2>/dev/null";
    
    $output = @shell_exec($command);
    
    if ($output) {
        $img = @imagecreatefromstring($output);
        if ($img) {
            $thumb = @imagecreatetruecolor(THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT);
            if ($thumb) {
                $origW = @imagesx($img);
                $origH = @imagesy($img);
                $ratio = min(THUMB_MAX_WIDTH / $origW, THUMB_MAX_HEIGHT / $origH, 1);
                $newW = (int)($origW * $ratio);
                $newH = (int)($origH * $ratio);
                $dstX = (int)((THUMB_MAX_WIDTH - $newW) / 2);
                $dstY = (int)((THUMB_MAX_HEIGHT - $newH) / 2);
                
                @imagecopyresampled($thumb, $img, $dstX, $dstY, 0, 0, $newW, $newH, $origW, $origH);
                @imagewebp($thumb, $thumbPath, IMAGE_QUALITY);
                @imagedestroy($thumb);
                @imagedestroy($img);
                return true;
            }
            @imagedestroy($img);
        }
    }
    
    return false;
}

function processVideo($tmp, $dir, $name) {
    global $thumbsDir;
    
    $hashedName = getHashedFilename($name);
    $filename = $hashedName;
    $path = $dir . $filename;
    $thumbFilename = 'thumb_' . pathinfo($hashedName, PATHINFO_FILENAME) . '.webp';
    $thumbPath = $thumbsDir . $thumbFilename;
    
    if (!@move_uploaded_file($tmp, $path)) {
        handleError('Falha ao salvar arquivo de vídeo.');
    }
    
    if (!createVideoThumbnail($path, $thumbPath)) {
        if (function_exists('imagecreatetruecolor')) {
            $img = @imagecreatetruecolor(THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT);
            if ($img) {
                $darkBlue = @imagecolorallocate($img, 30, 60, 120);
                @imagefilledrectangle($img, 0, 0, THUMB_MAX_WIDTH, THUMB_MAX_HEIGHT, $darkBlue);
                @imagewebp($img, $thumbPath, IMAGE_QUALITY);
                @imagedestroy($img);
            }
        }
    }
    
    return [
        'main' => $path,
        'thumb' => file_exists($thumbPath) ? $thumbPath : ''
    ];
}

// ==================== FIXED renderMedia FUNCTION - NO JS WARNING, JUST LINK ====================
function renderMedia($mediaPath, $thumbPath, $spoilered = false, $fileType = '') {
    if (!$mediaPath) return '';
    
    if (!file_exists($mediaPath)) {
        return '<div class="file"><p class="fileinfo">Arquivo não encontrado</p></div>';
    }
    
    $filename = basename($mediaPath);
    $size = filesize($mediaPath);
    $size_str = round($size / 1024, 2) . ' KB';
    $dims = '';
    
    if (!$fileType) {
        $fileType = mime_content_type($mediaPath);
    }
    
    global $webSpoilerImage;
    
    if (str_starts_with($fileType, 'image/')) {
        $imageInfo = @getimagesize($mediaPath);
        if ($imageInfo) {
            list($origW, $origH) = $imageInfo;
            $dims = ", {$origW}x{$origH}";
            
            if ($spoilered) {
                // FIXED: No-JS spoiler support - clicking the spoiler image takes you to the real image
                $mediaTag = '<a href="' . htmlspecialchars($mediaPath) . '" target="_blank" style="display:inline-block; line-height:0; font-size:0; text-decoration:none;">' .
                           '<img src="' . htmlspecialchars($webSpoilerImage) . '" alt="SPOILER" title="Clique para ver a imagem real" style="display:block; max-width:' . THUMB_MAX_WIDTH . 'px; max-height:' . THUMB_MAX_HEIGHT . 'px; background:none !important; border:none !important; margin:0 !important; padding:0 !important;">' .
                           '</a>';
            } else {
                $thumbSrc = ($thumbPath && file_exists($thumbPath)) ? $thumbPath : $mediaPath;
                $mediaTag = '<a href="' . htmlspecialchars($mediaPath) . '" target="_blank" class="image-link" style="display:inline-block; line-height:0; font-size:0; text-decoration:none; background:none !important; border:none !important; margin:0 !important; padding:0 !important;">' .
                           '<img src="' . htmlspecialchars($thumbSrc) . '" alt="" style="display:block; max-width:' . THUMB_MAX_WIDTH . 'px; max-height:' . THUMB_MAX_HEIGHT . 'px; background:none !important; border:none !important; margin:0 !important; padding:0 !important;">' .
                           '</a>';
            }
        } else {
            return '<div class="file"><p class="fileinfo">Arquivo de imagem inválido</p></div>';
        }
    } elseif (str_starts_with($fileType, 'video/')) {
        $videoInfo = @getimagesize($mediaPath);
        if ($videoInfo) {
            list($origW, $origH) = $videoInfo;
            $dims = ", {$origW}x{$origH}";
        }
        
        if ($spoilered) {
            // FIXED: No-JS spoiler support for videos - clicking the spoiler image takes you to the video
            $mediaTag = '<a href="' . htmlspecialchars($mediaPath) . '" target="_blank" style="display:inline-block; line-height:0; font-size:0; text-decoration:none;">' .
                       '<img src="' . htmlspecialchars($webSpoilerImage) . '" alt="SPOILER VÍDEO" title="Clique para ver o vídeo real" style="display:block; max-width:' . THUMB_MAX_WIDTH . 'px; max-height:' . THUMB_MAX_HEIGHT . 'px; background:none !important; border:none !important; margin:0 !important; padding:0 !important;">' .
                       '</a>';
        } else {
            $posterAttr = ($thumbPath && file_exists($thumbPath)) ? ' poster="' . htmlspecialchars($thumbPath) . '"' : '';
            $mediaTag = '<video controls preload="metadata" playsinline' . $posterAttr . ' style="display:block; max-width:' . THUMB_MAX_WIDTH . 'px; max-height:' . THUMB_MAX_HEIGHT . 'px; background:none !important; border:none !important; margin:0 !important; padding:0 !important;">' .
                       '<source src="' . htmlspecialchars($mediaPath) . '" type="' . $fileType . '">' .
                       'Seu navegador não suporta vídeos HTML5.' .
                       '</video>';
        }
    } else {
        return '<div class="file"><p class="fileinfo">Tipo de arquivo desconhecido</p></div>';
    }
    
    $fileinfo = '<p class="fileinfo" style="margin:0 0 2px 0 !important; padding:0 !important; line-height:normal; font-size:12px; background:none !important; border:none !important;"><span>Arquivo: <a href="' . htmlspecialchars($mediaPath) . '" target="_blank">' . htmlspecialchars($filename) . '</a></span><span class="unimportant">(' . $size_str . $dims . ($spoilered ? ', SPOILER' : '') . ')</span></p>';
    
    return '<div class="file" style="margin:0 0 5px 0 !important; padding:0 !important; background:none !important; border:none !important; line-height:0; font-size:0;">' . $fileinfo . $mediaTag . '</div>';
}

function formatName($raw, $capcode = null) {
    if (!str_contains($raw, '#')) {
        $name = trim($raw) ?: 'Anônimo';
        $html = htmlspecialchars($name);
    } else {
        list($name, $trip) = explode('#', $raw, 2);
        $name = trim($name) ?: 'Anônimo';
        
        $trip = mb_convert_encoding($trip, 'SJIS', 'UTF-8');
        $salt = substr($trip . 'H.', 1, 2);
        $salt = preg_replace('/[^\.-z]/', '.', $salt);
        $salt = strtr($salt, ':;<=>?@[\\]^_`', 'ABCDEFGabcdef');
        
        $hash = crypt($trip, $salt);
        $tripcode = substr($hash, -10);
        
        $html = htmlspecialchars($name) . ' <span class="trip">!' . htmlspecialchars($tripcode) . '</span>';
    }
    
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

function renderReply($reply_id, $message, $mediaPath, $thumbPath, $name, $created_at, $post_id, $deleted = false, $spoilered = false, $password = null, $capcode = null) {
    global $db;
    
    if ($deleted) {
        $message = '<i>Deletado</i>';
        $mediaPath = null;
        $thumbPath = null;
        $name = 'Anônimo';
        $capcode = null;
    }
    
    $timeInfo = formatTime($created_at);
    $mediaHtml = $mediaPath ? renderMedia($mediaPath, $thumbPath, $spoilered) : '';
    $filesHtml = $mediaHtml ? '<div class="files">' . $mediaHtml . '</div>' : '';
    
    // Usar a nova função de formatação com menções
    $formattedMessage = formatPostTextWithMentions($message, $db);
    
    // Obter menções deste post (IDs dos posts que mencionaram este)
    $mentions = getPostMentions($db, $reply_id);
    
    $deleteButton = '';
    if ($password) {
        $deleteButton = '<span class="delete-button-container">' .
                       '<a href="#" class="delete-button" data-post-id="' . $reply_id . '" data-is-reply="1" data-thread-id="' . $post_id . '">[Deletar]</a>' .
                       '</span>';
    }
    
    $mentionBadge = '';
    if (!empty($mentions)) {
        $mentionText = implode(' ', array_map(function($id) {
            return '<a href="#post-' . $id . '" class="mention-link mention-badge" onclick="highlightPost(' . $id . '); return false;">>>' . $id . '</a>';
        }, $mentions));
        $mentionBadge = '<span class="mention-badge-container"> ' . $mentionText . '</span>';
    }
    
    return '<div class="post reply" id="post-' . $reply_id . '">' .
        '<p class="intro">' .
        '<a id="' . $reply_id . '" class="post_anchor"></a>' .
        '<span class="name">' . formatName($name, $capcode) . '</span> ' .
        '<time datetime="' . $timeInfo['datetime'] . '">' . $timeInfo['formatted'] . '</time>&nbsp;' .
        '<a class="post_no" href="?post_id=' . $post_id . '#post-' . $reply_id . '">No.' . $reply_id . '</a>' .
        $mentionBadge .
        $deleteButton .
        '</p>' .
        $filesHtml .
        '<div class="body">' . $formattedMessage . '</div>' .
        '</div><br>';
}

function renderPost($id, $title, $message, $mediaPath, $thumbPath, $name, $created_at, $showReplyButton = true, $deleted = false, $spoilered = false, $sticky = false, $locked = false, $password = null, $capcode = null) {
    global $db;
    
    if ($deleted) {
        $title = 'Deletado';
        $message = '<i>Deletado</i>';
        $mediaPath = null;
        $thumbPath = null;
        $name = 'Anônimo';
        $capcode = null;
    }
    
    $timeInfo = formatTime($created_at);
    $replyCount = $showReplyButton ? getReplyCount($db, $id) : 0;
    
    $mediaHtml = renderMedia($mediaPath, $thumbPath, $spoilered);
    $filesHtml = $mediaHtml ? '<div class="files">' . $mediaHtml . '</div>' : '';
    
    // Usar a nova função de formatação com menções
    $formattedMessage = formatPostTextWithMentions($message, $db);
    
    // Obter menções deste post (IDs dos posts que mencionaram este)
    $mentions = getPostMentions($db, $id);
    
    $displayMessage = $formattedMessage;
    $toolongHtml = '';
    
    if ($showReplyButton && mb_strlen($message, 'UTF-8') > MAX_PREVIEW_CHARS) {
        $shortMessage = mb_substr($message, 0, MAX_PREVIEW_CHARS, 'UTF-8');
        $formattedShort = formatPostTextWithMentions($shortMessage, $db);
        $displayMessage = $formattedShort;
        $toolongHtml = '<span class="toolong">Post muito longo. Clique <a href="?post_id=' . $id . '#' . $id . '">aqui</a> para ver o texto completo.</span>';
    }
    
    $omittedHtml = '';
    if ($showReplyButton && $replyCount > MAIN_REPLIES_SHOWN) {
        $omittedCount = $replyCount - MAIN_REPLIES_SHOWN;
        $omittedHtml = '<p class="omitted">' . $omittedCount . ' post' . ($omittedCount > 1 ? 's' : '') . ' omitido' . ($omittedCount > 1 ? 's' : '') . '.</p>';
    }
    
    $repliesHtml = '';
    if ($showReplyButton && MAIN_REPLIES_SHOWN > 0 && $replyCount > 0) {
        $replyStmt = $db->prepare('SELECT * FROM posts WHERE parent_id = ? AND deleted = 0 AND is_reply = 1 AND approved = 1 ORDER BY created_at DESC LIMIT ?');
        $replyStmt->execute([$id, MAIN_REPLIES_SHOWN]);
        $replies = array_reverse($replyStmt->fetchAll(PDO::FETCH_ASSOC));
        
        foreach ($replies as $reply) {
            $repliesHtml .= renderReply(
                $reply['id'],
                $reply['message'],
                $reply['media'] ?? null,
                $reply['thumb'] ?? null,
                $reply['name'] ?? 'Anônimo',
                $reply['created_at'],
                $id,
                ($reply['deleted'] ?? 0) == 1,
                ($reply['spoilered'] ?? 0) == 1,
                $reply['password'] ?? null,
                $reply['capcode'] ?? null
            );
        }
    }
    
    $replyLinkHtml = $showReplyButton ? '<a href="?post_id=' . $id . '">[Responder]</a>' : '';
    
    $icons = '';
    if ($sticky) $icons .= ' 📌';
    if ($locked) $icons .= ' 🔒';
    
    $subjectHtml = $title ? '<span class="subject">' . htmlspecialchars($title) . '</span> ' : '';
    
    $deleteButton = '';
    if ($password) {
        $deleteButton = '<span class="delete-button-container">' .
                       '<a href="#" class="delete-button" data-post-id="' . $id . '" data-is-reply="0">[Deletar]</a>' .
                       '</span>';
    }
    
    $mentionBadge = '';
    if (!empty($mentions)) {
        $mentionText = implode(' ', array_map(function($id) {
            return '<a href="#post-' . $id . '" class="mention-link mention-badge" onclick="highlightPost(' . $id . '); return false;">>>' . $id . '</a>';
        }, $mentions));
        $mentionBadge = '<span class="mention-badge-container"> ' . $mentionText . '</span>';
    }
    
    return '<div class="thread' . ($sticky ? ' sticky-post' : '') . ($locked ? ' locked-thread' : '') . '" id="thread_' . $id . '" data-board="' . BOARD_NAME . '">' .
        $filesHtml .
        '<div class="post op" id="post-' . $id . '">' .
        '<p class="intro">' .
        $subjectHtml .
        '<span class="name">' . formatName($name, $capcode) . '</span> ' .
        '<time datetime="' . $timeInfo['datetime'] . '">' . $timeInfo['formatted'] . '</time>&nbsp;' .
        '<a class="post_no" href="?post_id=' . $id . '#post-' . $id . '">No.' . $id . '</a>' .
        $mentionBadge .
        $replyLinkHtml . $icons . $deleteButton .
        '</p>' .
        '<div class="body">' . $displayMessage . $toolongHtml . '</div>' .
        '</div>' .
        $omittedHtml .
        $repliesHtml .
        '<br class="clear"/><hr>' .
        '</div>';
}

function renderBanner($dir) {
    static $cache;
    if ($cache !== null) return $cache;

    $files = @glob($dir . '*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
    if (!$files) return '';

    $img = $files[array_rand($files)];
    return $cache =
        '<div class="board-banner">' .
        '<div class="banner-container">' .
        '<img src="' . htmlspecialchars($img) . '" alt="Banner" style="display:block; max-width:100%; background:none; border:none; margin:0; padding:0;">' .
        '</div>' .
        '</div>';
}

function deleteFiles($db, $postId, $isReply) {
    $table = 'posts';
    
    $stmt = $db->prepare("SELECT media, thumb FROM $table WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$post) return;
    
    if (!empty($post['media']) && file_exists($post['media'])) {
        @unlink($post['media']);
    }
    
    if (!empty($post['thumb']) && file_exists($post['thumb'])) {
        @unlink($post['thumb']);
    }
}

function getAvailableStyles() {
    $stylesDir = __DIR__ . '/stylesheets/';
    $styles = [];
    
    if (!is_dir($stylesDir)) {
        return $styles;
    }
    
    $files = scandir($stylesDir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'css') {
            $styleName = pathinfo($file, PATHINFO_FILENAME);
            $stylePath = 'stylesheets/' . $file;
            $styles[$styleName] = $stylePath;
        }
    }
    
    return $styles;
}

function getCurrentStyle() {
    if (isset($_COOKIE['board_style'])) {
        $style = $_COOKIE['board_style'];
        $styles = getAvailableStyles();
        if (isset($styles[$style])) {
            return $style;
        }
    }
    
    if (isset($_SESSION['board_style'])) {
        $style = $_SESSION['board_style'];
        $styles = getAvailableStyles();
        if (isset($styles[$style])) {
            return $style;
        }
    }
    
    $styles = getAvailableStyles();
    if (isset($styles['yotsuba'])) {
        return 'yotsuba';
    } elseif (!empty($styles)) {
        reset($styles);
        return key($styles);
    }
    
    return 'yotsuba';
}

function setStyleCookie($style) {
    setcookie('board_style', $style, time() + (86400 * 30), '/');
    $_SESSION['board_style'] = $style;
}

function deletePost($db, $postId, $isReply, $password) {
    $table = 'posts';
    
    $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$post) {
        return ['success' => false, 'message' => 'Post não encontrado.'];
    }
    
    if (empty($post['password'])) {
        return ['success' => false, 'message' => 'Este post não pode ser deletado pois não tem senha.'];
    }
    
    if (!password_verify($password, $post['password'])) {
        return ['success' => false, 'message' => 'Senha incorreta.'];
    }
    
    deleteFiles($db, $postId, $isReply);
    
    $stmt = $db->prepare("UPDATE $table SET deleted = 1 WHERE id = ?");
    $stmt->execute([$postId]);
    
    return ['success' => true, 'message' => 'Post deletado com sucesso.'];
}

$csrf = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        handleError('Token CSRF inválido', 403);
    }

    $postId = (int)($_POST['post_id'] ?? 0);
    $isReply = isset($_POST['is_reply']) && $_POST['is_reply'] === '1';
    $password = $_POST['delete_password'] ?? '';
    
    if ($postId <= 0) {
        handleError('ID do post inválido.');
    }
    
    if (empty($password)) {
        handleError('Por favor, insira a senha.');
    }
    
    $result = deletePost($db, $postId, $isReply, $password);
    
    if ($result['success']) {
        $_SESSION['success'] = $result['message'];
        $redirectUrl = $isReply && isset($_POST['thread_id']) ? 
            "?post_id=" . (int)$_POST['thread_id'] : "./";
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        handleError($result['message']);
    }
}

if (isset($_GET['style'])) {
    $styles = getAvailableStyles();
    $requestedStyle = $_GET['style'];
    
    if (isset($styles[$requestedStyle])) {
        setStyleCookie($requestedStyle);
        
        $redirectUrl = str_replace('?style=' . $requestedStyle, '', $_SERVER['REQUEST_URI']);
        $redirectUrl = str_replace('&style=' . $requestedStyle, '', $redirectUrl);
        
        if (strpos($redirectUrl, '?') === false && strpos($_SERVER['REQUEST_URI'], '?') !== false) {
            $redirectUrl .= '?';
        }
        
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// =========================
// HANDLING DE POSTAGEM COM FILTRO DE PALAVRAS, ANTI-SPAM E MENÇÕES
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        handleError('Token CSRF inválido', 403);
    }

    // Verificar modo apenas leitura
    if ($readonly_mode === '1') {
        handleError('O fórum está em modo apenas leitura. No momento, não é possível fazer novos posts.', 403);
    }

    $name = trim($_POST['name'] ?? '') ?: 'Anônimo';
    $body = trim($_POST['body'] ?? '');
    if ($body === '') handleError('Mensagem obrigatória');
    
    // ===== VERIFICAÇÃO ANTI-SPAM =====
    $spamCheck = checkAntiSpam();
    if ($spamCheck !== null) {
        handleError($spamCheck);
    }
    // ==================================
    
    // ===== FILTRO DE PALAVRAS =====
    $filters = loadWordFilters();
    $blockedWord = checkWordFilters($body, $filters);
    if ($blockedWord !== null) {
        handleError('Seu post contém uma palavra bloqueada: "' . htmlspecialchars($blockedWord) . '". Por favor, remova-a e tente novamente.');
    }
    // ================================
    
    $spoilered = 0;
    $hasFile = !empty($_FILES['file']['tmp_name']) && $_FILES['file']['size'] > 0;
    
    if ($hasFile) {
        $spoilered = isset($_POST['spoiler']) && $_POST['spoiler'] === 'on' ? 1 : 0;
    }

    $iphash = substr(sha1($_SERVER['REMOTE_ADDR'] ?? ''), 0, 16);

    $password = trim($_POST['password'] ?? '');
    $hashedPassword = null;
    if (!empty($password)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    }

    // Determinar se o post precisa de aprovação
    $approved = $requires_approval ? 0 : 1;

    if (!empty($_POST['thread'])) {
        $pid = (int)$_POST['thread'];
        
        $stmt = $db->prepare('SELECT locked FROM posts WHERE id = ? AND deleted = 0 AND is_reply = 0 AND approved = 1');
        $stmt->execute([$pid]);
        $thread = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$thread) {
            handleError('Tópico não encontrado ou deletado.', 404);
        }
        
        if (($thread['locked'] ?? 0) == 1) {
            handleError('Este tópico está trancado. Não podem ser postadas novas respostas.', 403);
        }
        
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

            if (str_starts_with($type, 'image/')) {
                $result = processAndOptimizeImage($tmp, $uploadsDir, $_FILES['file']['name']);
                $mediaPath = $result['main'];
                $thumbPath = $result['thumb'];
            } elseif (str_starts_with($type, 'video/')) {
                $result = processVideo($tmp, $uploadsDir, $_FILES['file']['name']);
                $mediaPath = $result['main'];
                $thumbPath = $result['thumb'];
            }
        }
        
        // Inserir resposta com status de aprovação baseado na configuração
        $stmt = $db->prepare("INSERT INTO posts (message, media, thumb, name, postiphash, spoilered, password, is_reply, parent_id, approved) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
        $stmt->execute([$body, $mediaPath, $thumbPath, $name, $iphash, $spoilered, $hashedPassword, $pid, $approved]);
        
        // Obter o ID do post inserido
        $new_post_id = $db->lastInsertId();
        
        // ===== REGISTRAR MENÇÕES =====
        registerMentions($db, $new_post_id, $body);
        // ==============================
        
        $updateStmt = $db->prepare("UPDATE posts SET updated_at = datetime('now', 'localtime') WHERE id = ?");
        $updateStmt->execute([$pid]);
        
        // ===== REGISTRAR POST BEM-SUCEDIDO NO ANTI-SPAM =====
        registerAntiSpam();
        // ===================================================
        
        if ($requires_approval) {
            $_SESSION['success'] = 'Sua resposta foi enviada e aguarda aprovação de um moderador.';
        } else {
            $_SESSION['success'] = 'Resposta publicada com sucesso!';
        }
        
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header("Location: ?post_id=$pid");
        exit;
    }

    $subject = trim($_POST['subject'] ?? '');
    
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

        if (str_starts_with($type, 'image/')) {
            $result = processAndOptimizeImage($tmp, $uploadsDir, $_FILES['file']['name']);
            $mediaPath = $result['main'];
            $thumbPath = $result['thumb'];
        } elseif (str_starts_with($type, 'video/')) {
            $result = processVideo($tmp, $uploadsDir, $_FILES['file']['name']);
            $mediaPath = $result['main'];
            $thumbPath = $result['thumb'];
        }
    }

    // Inserir novo tópico com status de aprovação baseado na configuração
    $stmt = $db->prepare("INSERT INTO posts (title, message, media, thumb, name, postiphash, spoilered, password, is_reply, approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
    $stmt->execute([$subject, $body, $mediaPath, $thumbPath, $name, $iphash, $spoilered, $hashedPassword, $approved]);
    
    // Obter o ID do post inserido
    $new_post_id = $db->lastInsertId();
    
    // ===== REGISTRAR MENÇÕES =====
    registerMentions($db, $new_post_id, $body);
    // ==============================
    
    // ===== REGISTRAR POST BEM-SUCEDIDO NO ANTI-SPAM =====
    registerAntiSpam();
    // ===================================================
    
    if ($requires_approval) {
        $_SESSION['success'] = 'Seu tópico foi enviado e aguarda aprovação de um moderador.';
    } else {
        $_SESSION['success'] = 'Tópico publicado com sucesso!';
    }
    
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ./');
    exit;
}

/* =========================
   SAÍDA
========================= */
$post_id = filter_input(INPUT_GET, 'post_id', FILTER_VALIDATE_INT) ?: null;

// Se tiver um hash na URL, extrair o ID do post para redirecionar
if (isset($_SERVER['REQUEST_URI']) && preg_match('/#post-(\d+)/', $_SERVER['REQUEST_URI'], $matches)) {
    $target_id = $matches[1];
    $post_info = getPostInfo($db, $target_id);
    if ($post_info && $post_info['is_reply'] == 1) {
        // Se for uma resposta, redirecionar para o tópico principal com o hash
        $post_id = $post_info['parent_id'];
        // Manter o hash na URL
        // O JavaScript vai cuidar do scroll
    }
}

$currentStyle = getCurrentStyle();
$availableStyles = getAvailableStyles();

// Obter tempo restante para anti-spam (opcional - pode ser usado no template)
$antispam_remaining = getAntiSpamRemainingTime();

while (ob_get_level() > 0) {
    ob_end_clean();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(BOARD_TITLE) ?></title>

<link rel="stylesheet" media="screen" href="<?= htmlspecialchars($availableStyles[$currentStyle] ?? 'stylesheets/miku.css') ?>?v=0" title="<?= htmlspecialchars(ucfirst($currentStyle)) ?>">

<?php foreach ($availableStyles as $styleName => $stylePath): ?>
    <?php if ($styleName !== $currentStyle): ?>
        <link rel="alternate stylesheet" title="<?= htmlspecialchars(ucfirst($styleName)) ?>" href="<?= htmlspecialchars($stylePath) ?>?v=0">
    <?php endif; ?>
<?php endforeach; ?>

<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
<link rel="stylesheet" href="stylesheets/font-awesome/css/font-awesome.min.css?v=0">
<link rel="stylesheet" href="static/flags/flags.css?v=0">

<style>
/* Reset básico */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

/* Estilos de formatação de texto */
.greentext {
    color: #789922;
}

.redtext {
    color: #ff0000;
    font-weight: bold;
}

.spoiler-text {
    background-color: #000;
    color: #000;
    cursor: pointer;
}

.spoiler-text.revealed {
    background-color: transparent;
    color: inherit;
}

/* Capcode styles */
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

/* ========== CONTAINERS DE MÍDIA - SEM ESPAÇAMENTO ========== */
.image-link {
    display: inline-block;
    line-height: 0;
    font-size: 0;
    text-decoration: none;
    background: none !important;
    border: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

.image-link img {
    display: block;
    max-width: 250px;
    max-height: 250px;
    border: none !important;
    background: none !important;
    margin: 0 !important;
    padding: 0 !important;
    transition: transform 0.2s;
}

.image-link img:hover {
    transform: scale(1.02);
}

.image-spoiler-container {
    display: inline-block;
    line-height: 0;
    font-size: 0;
    background: none !important;
    border: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

.spoiler-overlay,
.spoiler-real {
    display: block;
    max-width: 250px;
    max-height: 250px;
    border: none !important;
    background: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

video {
    display: block;
    max-width: 250px;
    max-height: 250px;
    background: none !important;
    border: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

.video-spoiler {
    display: inline-block;
    line-height: 0;
    font-size: 0;
    background: none !important;
    border: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

.video-spoiler img {
    display: block;
    max-width: 250px;
    max-height: 250px;
    border: none !important;
    background: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

.files {
    margin: 0 !important;
    padding: 0 !important;
    background: none !important;
    border: none !important;
    line-height: 0;
    font-size: 0;
}

.file {
    margin: 0 0 5px 0 !important;
    padding: 0 !important;
    background: none !important;
    border: none !important;
    line-height: 0;
    font-size: 0;
}

.fileinfo {
    margin: 0 0 2px 0 !important;
    padding: 0 !important;
    line-height: normal;
    font-size: 12px;
    background: none !important;
    border: none !important;
}

/* Lightbox */
.image-lightbox {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.9);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.image-lightbox.show {
    display: flex;
}

.image-lightbox img {
    max-width: 90%;
    max-height: 90%;
    object-fit: contain;
}

.image-lightbox-close {
    position: absolute;
    top: 20px;
    right: 30px;
    color: white;
    font-size: 40px;
    cursor: pointer;
    z-index: 10001;
}

.image-lightbox-close:hover {
    color: #ccc;
}

/* Estilos de cabeçalho */
.board-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 10px 0;
    margin-bottom: 20px;
    border-bottom: 1px solid #ddd;
}

.board-title {
    font-size: 32px;
    font-weight: bold;
    color: #0f0c5d;
    margin-bottom: 10px;
    text-align: center;
    width: 100%;
}

.board-subtitle {
    font-size: 16px;
    color: #666;
    margin-bottom: 15px;
    text-align: center;
    width: 100%;
}

.board-controls {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
    width: 100%;
}

.board-controls a {
    padding: 8px 15px;
    background: #4CAF50;
    color: white;
    text-decoration: none;
    border-radius: 3px;
    font-size: 14px;
}

.board-controls a:hover {
    background: #45a049;
}

/* TEXTO ACIMA DO BANNER */
.top-message {
    text-align: center;
    margin-bottom: 15px;
    padding: 10px;
    background: #f8f8f8;
    border-radius: 5px;
    border: 1px solid #ddd;
}

.top-message p {
    margin: 5px 0;
}

.top-message .small {
    font-size: 12px;
    color: #666;
}

/* Troca de estilo */
.style-switcher {
    margin: 10px 0;
    text-align: center;
}

.style-button {
    padding: 8px 15px;
    background: #2196F3;
    color: white;
    text-decoration: none;
    border-radius: 3px;
    font-size: 14px;
    cursor: pointer;
    transition: background 0.2s;
    display: inline-block;
}

.style-button:hover {
    background: #1976D2;
}

.style-dropdown {
    display: none;
    position: absolute;
    background: white;
    border: 1px solid #ddd;
    border-radius: 5px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    z-index: 1001;
    min-width: 150px;
    margin-top: 5px;
}

.style-dropdown.show {
    display: block;
}

.style-dropdown a {
    display: block;
    padding: 10px 15px;
    color: #333;
    text-decoration: none;
    border-bottom: 1px solid #eee;
    transition: background 0.2s;
}

.style-dropdown a:hover {
    background: #f5f5f5;
}

.style-dropdown a.current {
    background: #e0e0e0;
    font-weight: bold;
}

/* Banner centralizado */
.board-banner {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 20px 0;
    text-align: center;
}

.banner-container {
    max-width: 100%;
}

.banner-container img {
    display: block;
    max-width: 100%;
    height: auto;
    border-radius: 5px;
    background: none;
    border: none;
    margin: 0;
    padding: 0;
}

.style-form {
    display: none;
}

.no-js .style-form {
    display: block;
    margin: 20px 0;
    text-align: center;
}

.no-js .style-dropdown {
    display: none;
}

.no-js .style-button {
    display: none;
}

/* Formulário de deleção */
.delete-form {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 0 20px rgba(0,0,0,0.3);
    z-index: 10000;
    width: 300px;
}

.delete-form.show {
    display: block;
}

.delete-form-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
}

.delete-form-overlay.show {
    display: block;
}

.delete-form h3 {
    margin-top: 0;
    color: #333;
}

.delete-form input[type="password"] {
    width: 100%;
    padding: 8px;
    margin: 10px 0;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-sizing: border-box;
}

.delete-form-buttons {
    display: flex;
    justify-content: space-between;
    margin-top: 15px;
}

.delete-form-buttons button {
    padding: 8px 15px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.delete-form-buttons button[type="submit"] {
    background: #f44336;
    color: white;
}

.delete-form-buttons button[type="submit"]:hover {
    background: #d32f2f;
}

.delete-form-buttons button[type="button"] {
    background: #ddd;
    color: #333;
}

.delete-form-buttons button[type="button"]:hover {
    background: #ccc;
}

/* Botão de deletar */
.delete-button-container {
    margin-left: 10px;
}

.delete-button {
    color: #f44336;
    text-decoration: none;
    font-size: 12px;
    cursor: pointer;
    padding: 0 5px;
}

.delete-button:hover {
    text-decoration: underline;
}

/* Modo apenas leitura - aviso */
.readonly-notice {
    background-color: #ffeb3b;
    border: 2px solid #f44336;
    color: #333;
    font-weight: bold;
    padding: 15px;
    margin: 20px auto;
    text-align: center;
    border-radius: 5px;
    max-width: 800px;
}

.readonly-notice i {
    margin-right: 10px;
    font-size: 20px;
}

/* Responsividade */
@media (max-width: 768px) {
    .board-title {
        font-size: 24px;
    }
    
    .board-subtitle {
        font-size: 14px;
    }
    
    .board-controls {
        gap: 8px;
    }
    
    .board-controls a {
        padding: 6px 12px;
        font-size: 13px;
    }
    
    .style-button {
        padding: 8px 12px;
        font-size: 13px;
    }
    
    .style-dropdown {
        right: 0;
        left: 0;
        margin: 0 auto 5px auto;
        width: 90%;
        max-width: 200px;
    }
    
    .delete-form {
        width: 90%;
        max-width: 300px;
    }
    
    .image-lightbox img {
        max-width: 95%;
        max-height: 80%;
    }
    
    .image-lightbox-close {
        top: 10px;
        right: 20px;
        font-size: 30px;
    }
}

footer {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
    text-align: center;
    color: #666;
    font-size: 12px;
}

/* Aviso anti-spam (opcional) */
.antispam-notice {
    font-size: 11px;
    color: #666;
    margin-top: 5px;
    text-align: center;
}
</style>

<script>
const THUMB_MAX_WIDTH = 250;
const THUMB_MAX_HEIGHT = 250;
const ANTI_SPAM_TIME = <?= ANTI_SPAM_TIME ?>;

document.documentElement.className = document.documentElement.className.replace(/\bno-js\b/, '') + ' js';

let currentDeletePostId = null;
let currentDeleteIsReply = false;
let currentDeleteThreadId = null;
let currentLightboxImage = null;

// ===== FUNÇÃO PARA DESTACAR POST MENCIONADO =====
function highlightPost(postId) {
    var postElement = document.getElementById('post-' + postId);
    if (postElement) {
        postElement.classList.add('highlight-post');
        postElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(function() {
            postElement.classList.remove('highlight-post');
        }, 2000);
    } else {
        // Se não encontrar o post, pode ser que esteja em outra página
        // Tenta redirecionar para a página correta
        window.location.href = '?post_id=' + postId + '#post-' + postId;
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

    var styleButton = document.getElementById('style-button');
    var styleDropdown = document.getElementById('style-dropdown');
    
    if (styleButton && styleDropdown) {
        styleButton.addEventListener('click', function(e) {
            e.preventDefault();
            styleDropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', function(e) {
            if (!styleButton.contains(e.target) && !styleDropdown.contains(e.target)) {
                styleDropdown.classList.remove('show');
            }
        });
        
        var styleLinks = styleDropdown.querySelectorAll('a[data-style]');
        styleLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var style = this.getAttribute('data-style');
                
                var links = document.querySelectorAll('link[rel="stylesheet"], link[rel="alternate stylesheet"]');
                links.forEach(function(link) {
                    if (link.title === style.charAt(0).toUpperCase() + style.slice(1)) {
                        link.disabled = false;
                        link.rel = 'stylesheet';
                    } else {
                        link.disabled = true;
                        link.rel = 'alternate stylesheet';
                    }
                });
                
                styleLinks.forEach(function(l) {
                    l.classList.remove('current');
                });
                this.classList.add('current');
                
                document.cookie = "board_style=" + style + "; path=/; max-age=" + (30 * 24 * 60 * 60);
                
                styleDropdown.classList.remove('show');
                styleButton.innerHTML = '🎨 ' + style.charAt(0).toUpperCase() + style.slice(1);
                
                var notification = document.createElement('div');
                notification.style.position = 'fixed';
                notification.style.top = '10px';
                notification.style.right = '10px';
                notification.style.backgroundColor = 'rgba(0,0,0,0.8)';
                notification.style.color = 'white';
                notification.style.padding = '10px';
                notification.style.borderRadius = '5px';
                notification.style.zIndex = '1000';
                notification.textContent = 'Estilo alterado para ' + style.charAt(0).toUpperCase() + style.slice(1);
                document.body.appendChild(notification);
                
                setTimeout(function() {
                    notification.remove();
                }, 2000);
            });
        });
    }
    
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('spoiler-text')) {
            e.target.classList.toggle('revealed');
            return;
        }
        
        if (e.target.classList.contains('spoiler-overlay')) {
            var container = e.target.closest('.image-spoiler-container');
            if (container) {
                var overlay = container.querySelector('.spoiler-overlay');
                var realImage = container.querySelector('.spoiler-real');
                
                if (overlay && realImage) {
                    overlay.style.display = 'none';
                    realImage.style.display = 'block';
                    realImage.classList.add('revealed');
                }
            }
            return;
        }
        
        if (e.target.closest('.image-link') && !e.target.closest('.image-spoiler-container')) {
            e.preventDefault();
            var link = e.target.closest('.image-link');
            var fullSrc = link.getAttribute('href');
            showLightbox(fullSrc);
            return;
        }
        
        if (e.target.closest('.video-spoiler')) {
            var container = e.target.closest('.video-spoiler');
            var videoSrc = container.getAttribute('data-src');
            
            if (videoSrc) {
                var video = document.createElement('video');
                video.controls = true;
                video.preload = 'metadata';
                video.playsInline = true;
                video.style.maxWidth = THUMB_MAX_WIDTH + 'px';
                video.style.maxHeight = THUMB_MAX_HEIGHT + 'px';
                
                var source = document.createElement('source');
                source.src = videoSrc;
                source.type = 'video/mp4';
                    
                video.appendChild(source);
                container.innerHTML = '';
                container.appendChild(video);
                video.play();
            }
            return;
        }
        
        if (e.target.classList.contains('delete-button')) {
            e.preventDefault();
            var postId = e.target.getAttribute('data-post-id');
            var isReply = e.target.getAttribute('data-is-reply') === '1';
            var threadId = e.target.getAttribute('data-thread-id');
            
            if (postId) {
                showDeleteForm(postId, isReply, threadId);
            }
            return;
        }
        
        if (e.target.classList.contains('image-lightbox-close') || e.target.classList.contains('image-lightbox')) {
            hideLightbox();
            return;
        }
        
        if (e.target.classList.contains('delete-form-overlay') || e.target.classList.contains('cancel-delete')) {
            hideDeleteForm();
            return;
        }
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.altKey && e.key === 's') {
            e.preventDefault();
            
            document.querySelectorAll('.spoiler-text').forEach(function(el) {
                el.classList.add('revealed');
            });
            
            document.querySelectorAll('.image-spoiler-container').forEach(function(container) {
                var overlay = container.querySelector('.spoiler-overlay');
                var realImage = container.querySelector('.spoiler-real');
                
                if (overlay && realImage) {
                    overlay.style.display = 'none';
                    realImage.style.display = 'block';
                    realImage.classList.add('revealed');
                }
            });
            
            var notification = document.createElement('div');
            notification.style.position = 'fixed';
            notification.style.top = '10px';
            notification.style.right = '10px';
            notification.style.backgroundColor = 'rgba(0,0,0,0.8)';
            notification.style.color = 'white';
            notification.style.padding = '10px';
            notification.style.borderRadius = '5px';
            notification.style.zIndex = '1000';
            notification.textContent = 'Todos os spoilers revelados!';
            document.body.appendChild(notification);
            
            setTimeout(function() {
                notification.remove();
            }, 3000);
        }
        
        if (e.key === 'Escape') {
            hideDeleteForm();
            hideLightbox();
        }
    });
    
    var fileInput = document.getElementById('upload_file');
    var spoilerRow = document.getElementById('spoiler-row');
    var spoilerCheckbox = document.getElementById('spoiler-checkbox');
    
    if (fileInput && spoilerRow && spoilerCheckbox) {
        function toggleSpoilerRow() {
            // Always show the spoiler row for non-JS users
            // For JS users, we can still enhance the experience
            if (fileInput.files.length > 0) {
                spoilerRow.style.display = '';
            } else {
                spoilerRow.style.display = '';
                // Don't auto-check or disable, let user decide
            }
        }
        
        fileInput.addEventListener('change', toggleSpoilerRow);
        toggleSpoilerRow();
    }
});

function showDeleteForm(postId, isReply, threadId) {
    currentDeletePostId = postId;
    currentDeleteIsReply = isReply;
    currentDeleteThreadId = threadId;
    
    document.getElementById('delete-overlay').classList.add('show');
    document.getElementById('delete-form').classList.add('show');
    document.getElementById('delete-password').focus();
}

function hideDeleteForm() {
    currentDeletePostId = null;
    currentDeleteIsReply = false;
    currentDeleteThreadId = null;
    
    document.getElementById('delete-overlay').classList.remove('show');
    document.getElementById('delete-form').classList.remove('show');
    document.getElementById('delete-password').value = '';
}

function showLightbox(imageSrc) {
    currentLightboxImage = imageSrc;
    
    var lightbox = document.getElementById('image-lightbox');
    var lightboxImg = document.getElementById('lightbox-image');
    
    lightboxImg.src = imageSrc;
    lightbox.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function hideLightbox() {
    var lightbox = document.getElementById('image-lightbox');
    lightbox.classList.remove('show');
    document.body.style.overflow = '';
    currentLightboxImage = null;
}

function submitDeleteForm() {
    if (!currentDeletePostId) return false;
    
    document.getElementById('delete-post-id').value = currentDeletePostId;
    document.getElementById('delete-is-reply').value = currentDeleteIsReply ? '1' : '0';
    document.getElementById('delete-thread-id').value = currentDeleteThreadId || '';
    
    return true;
}
</script>
</head>
<body class="no-js">

<header>
<div class="top-message">
[ <a href="./mod.php">Moderar</a> / <a href="../news/index.html">notícias</a>]
</div>

<?= renderBanner($bannersDir) ?>

<div class="board-header">
    <div class="board-title"><?= htmlspecialchars(BOARD_TITLE) ?></div>
    <div class="board-subtitle"><?= htmlspecialchars(BOARD_SUBTITLE) ?></div>
    <div class="board-controls">
        <a href="./">📋 Índice</a>
        <a href="catalogo.php">📚 Catálogo</a>
    </div>
</div>

<div class="style-switcher">
    <a href="#" class="style-button" id="style-button">🎨 <?= htmlspecialchars(ucfirst($currentStyle)) ?></a>
    <div class="style-dropdown" id="style-dropdown">
        <?php foreach ($availableStyles as $styleName => $stylePath): ?>
            <a href="#" data-style="<?= htmlspecialchars($styleName) ?>" class="<?= $styleName === $currentStyle ? 'current' : '' ?>">
                <?= htmlspecialchars(ucfirst($styleName)) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="style-form">
    <form method="get" action="">
        <?php if ($post_id): ?>
            <input type="hidden" name="post_id" value="<?= $post_id ?>">
        <?php endif; ?>
        <label for="style-select">Mudar Estilo: </label>
        <select name="style" id="style-select" onchange="this.form.submit()">
            <?php foreach ($availableStyles as $styleName => $stylePath): ?>
                <option value="<?= htmlspecialchars($styleName) ?>" <?= $styleName === $currentStyle ? 'selected' : '' ?>>
                    <?= htmlspecialchars(ucfirst($styleName)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript>
            <input type="submit" value="Aplicar">
        </noscript>
    </form>
</div>

<?php if ($antispam_remaining > 0): ?>
<div class="antispam-notice">
    ⏱️ Você pode postar novamente em <?= $antispam_remaining ?> segundos.
</div>
<?php endif; ?>

<?php if ($readonly_mode === '1'): ?>
<div class="readonly-notice">
    <i>🔒</i> O fórum está em modo apenas leitura. No momento, não é possível fazer novos posts ou respostas.
</div>
<?php endif; ?>

</header>

<?php if ($post_id): ?>
<a name="top"></a>
<div class="banner">Modo de postagem: Resposta <a class="unimportant" href="./">[Voltar]</a> <a class="unimportant" href="#bottom">[Ir para o final]</a></div>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
<p class="error"><?= htmlspecialchars($_SESSION['error']) ?></p>
<?php unset($_SESSION['error']); endif; ?>

<?php if (!empty($_SESSION['success'])): ?>
<p class="success"><?= htmlspecialchars($_SESSION['success']) ?></p>
<?php unset($_SESSION['success']); endif; ?>

<?php if ($post_id !== null && $post_id > 0): ?>
    <?php
    $stmt = $db->prepare('SELECT * FROM posts WHERE id = ? AND deleted = 0 AND is_reply = 0 AND approved = 1');
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$post) {
        handleError('Post não encontrado ou aguardando aprovação.', 404);
    }
    
    $is_locked = ($post['locked'] ?? 0) == 1;
    $is_sticky = ($post['sticky'] ?? 0) == 1;
    ?>
    
    <?php if (!$is_locked && $readonly_mode !== '1'): ?>
    <form name="post" enctype="multipart/form-data" action="" method="post">
        <input type="hidden" name="thread" value="<?= $post_id ?>">
        <input type="hidden" name="board" value="<?= BOARD_NAME ?>">
        <table>
            <tr><th>Nome</th><td><input type="text" name="name" size="25" maxlength="35" autocomplete="off" placeholder="Anônimo#trip"></td></tr>
            <tr><th>Senha (opcional)</th><td><input type="password" name="password" size="25" maxlength="100" autocomplete="off" placeholder="Para deletar post posteriormente"></td></tr>
            <tr><th>Comentário</th><td><textarea name="body" id="body" rows="5" cols="35" required></textarea></td></tr>
            <tr id="upload"><th>Arquivo</th><td><input type="file" name="file" id="upload_file"></td></tr>
            <!-- FIXED: Spoiler row always visible for non-JS users -->
            <tr id="spoiler-row"><th>Spoiler</th><td><label><input type="checkbox" name="spoiler" id="spoiler-checkbox"> Marcar imagem como spoiler</label></td></tr>
            
            </noscript>
            <tr><td colspan="2"><input accesskey="s" type="submit" name="post" value="Nova Resposta" /></td></tr>
        </table>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    </form>
    <?php elseif ($is_locked): ?>
        <div class="locked-notice" style="background-color: #ffcccc; border: 1px solid #ff0000; padding: 10px; margin: 10px; text-align: center; font-weight: bold;">
            🔒 Este tópico está trancado. Não podem ser postadas novas respostas.
        </div>
    <?php endif; ?>
    
    <hr>
    
    <form name="postcontrols" action="" method="post">
        <input type="hidden" name="board" value="<?= BOARD_NAME ?>" />
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
            $is_sticky,
            $is_locked,
            $post['password'] ?? null,
            $post['capcode'] ?? null
        );
        ?>
        
        <?php
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
                $reply['password'] ?? null,
                $reply['capcode'] ?? null
            );
        }
        ?>
        
        <div id="thread-interactions">
            <span id="thread-links">
                <a id="thread-return" href="./">[Voltar]</a>
                <a id="thread-top" href="#top">[Ir para o topo]</a>
            </span>
            <?php if ($readonly_mode !== '1'): ?>
            <span id="thread-quick-reply">
                <a id="link-quick-reply" href="#">[Postar uma Resposta]</a>
            </span>
            <?php endif; ?>
            <div class="clearfix"></div>
        </div>
    </form>
    <a name="bottom"></a>
    
<?php else: ?>
    
    <?php if ($readonly_mode !== '1'): ?>
    <form name="post" enctype="multipart/form-data" action="" method="post">
        <input type="hidden" name="board" value="<?= BOARD_NAME ?>">
        <table>
            <tr><th>Nome</th><td><input type="text" name="name" size="25" maxlength="35" autocomplete="off" placeholder="Anônimo#trip"></td></tr>
            <tr><th>Senha (opcional)</th><td><input type="password" name="password" size="25" maxlength="100" autocomplete="off" placeholder="Para deletar post posteriormente"></td></tr>
            <tr><th>Tópico</th><td><input style="float:left;" type="text" name="subject" size="25" maxlength="100" autocomplete="off" placeholder="Opcional"></td></tr>
            <tr><th>Post</th><td><textarea name="body" id="body" rows="5" cols="35" required></textarea></td></tr>
            <tr id="upload"><th>Arquivo</th><td><input type="file" name="file" id="upload_file"></td></tr>
            <!-- FIXED: Spoiler row always visible for non-JS users -->
            <tr id="spoiler-row"><th>Spoiler</th><td><label><input type="checkbox" name="spoiler" id="spoiler-checkbox"> Marcar imagem como spoiler</label></td></tr>
            
            <tr><td colspan="2"><input accesskey="s" type="submit" name="post" value="Novo Tópico" /></td></tr>
        </table>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    </form>
    <?php endif; ?>
    
    <hr>
    
    <?php
    $totalPosts = (int)$db->query('SELECT COUNT(*) FROM posts WHERE deleted = 0 AND is_reply = 0 AND approved = 1')->fetchColumn();
    $totalPages = max(1, (int)ceil($totalPosts / POSTS_PER_PAGE));
    
    $page = max(1, (int)($_GET['page'] ?? 1));
    
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    
    $offset = ($page - 1) * POSTS_PER_PAGE;
    
    $stmt = $db->prepare("
        SELECT * FROM posts 
        WHERE deleted = 0 AND is_reply = 0 AND approved = 1
        ORDER BY sticky DESC, updated_at DESC 
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute([POSTS_PER_PAGE, $offset]);
    ?>
    
    <form name="postcontrols" action="" method="post">
        <input type="hidden" name="board" value="<?= BOARD_NAME ?>" />
        <?php
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
                $row['password'] ?? null,
                $row['capcode'] ?? null
            );
        }
        ?>
    </form>
    
    <?php if ($totalPages > 1): ?>
    <div class="pages">
        <?php
        $prevText = $page > 1 ? '<a href="?page=' . ($page - 1) . '">Anterior</a>' : 'Anterior';
        $nextText = $page < $totalPages ? '<a href="?page=' . ($page + 1) . '">Próximo</a>' : 'Próximo';
        
        echo $prevText . ' [';
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === $page) {
                echo '<a class="selected">' . $i . '</a>';
            } else {
                echo '<a href="?page=' . $i . '">' . $i . '</a>';
            }
        }
        echo '] ' . $nextText;
        ?>
    </div>
    <?php endif; ?>
    
<?php endif; ?>

<div class="image-lightbox" id="image-lightbox">
    <div class="image-lightbox-close">&times;</div>
    <img id="lightbox-image" src="" alt="">
</div>

<div class="delete-form-overlay" id="delete-overlay"></div>
<div class="delete-form" id="delete-form">
    <form action="" method="post" onsubmit="return submitDeleteForm()">
        <h3>Deletar Post</h3>
        <p>Digite a senha para deletar este post:</p>
        <input type="password" name="delete_password" id="delete-password" required>
        <div class="delete-form-buttons">
            <button type="button" class="cancel-delete">Cancelar</button>
            <button type="submit">Deletar</button>
        </div>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="post_id" id="delete-post-id" value="">
        <input type="hidden" name="is_reply" id="delete-is-reply" value="0">
        <input type="hidden" name="thread_id" id="delete-thread-id" value="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    </form>
</div>

<br clear="all">
<footer>
<a href="https://github.com/peidorreiro/LaranjaEngine">LaranjaEngine</a>
<br><small>Alt+S para revelar todos os spoilers (requer JavaScript)</small></footer>
</body>
</html>
