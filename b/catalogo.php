<?php
// catalogo.php - Simple catalog page with working theme switcher
declare(strict_types=1);

// Set timezone
date_default_timezone_set('America/Sao_Paulo');

// Database connection
$uploadsDir = 'uploads/';
try {
    $db = new PDO('sqlite:' . $uploadsDir . 'message_board.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro ao conectar ao banco de dados: " . $e->getMessage());
}

// Define constants
define('THUMB_MAX_WIDTH', 250);
define('THUMB_MAX_HEIGHT', 250);
define('USE_WEBP', true);
define('BOARD_TITLE', '/b/ - Aleatório');

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Style functions
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
    // Check cookie first
    if (isset($_COOKIE['board_style'])) {
        $style = $_COOKIE['board_style'];
        $styles = getAvailableStyles();
        if (isset($styles[$style])) {
            return $style;
        }
    }
    
    // Check session
    if (isset($_SESSION['board_style'])) {
        $style = $_SESSION['board_style'];
        $styles = getAvailableStyles();
        if (isset($styles[$style])) {
            return $style;
        }
    }
    
    // Default to first available style
    $styles = getAvailableStyles();
    if (isset($styles['yotsuba'])) {
        return 'yotsuba';
    } elseif (isset($styles['miku'])) {
        return 'miku';
    } elseif (!empty($styles)) {
        reset($styles);
        return key($styles);
    }
    
    return 'yotsuba';
}

// Handle style switching
if (isset($_GET['style'])) {
    $styles = getAvailableStyles();
    $requestedStyle = $_GET['style'];
    
    if (isset($styles[$requestedStyle])) {
        // Set cookie for 30 days
        setcookie('board_style', $requestedStyle, time() + (86400 * 30), '/');
        $_SESSION['board_style'] = $requestedStyle;
        
        // Redirect to remove style parameter from URL
        $redirectUrl = str_replace('?style=' . $requestedStyle, '', $_SERVER['REQUEST_URI']);
        $redirectUrl = str_replace('&style=' . $requestedStyle, '', $redirectUrl);
        
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Get current style
$currentStyle = getCurrentStyle();
$availableStyles = getAvailableStyles();

// Get all threads
$stmt = $db->prepare("
    SELECT p.*, 
           (SELECT COUNT(*) FROM posts r WHERE r.parent_id = p.id AND r.deleted = 0 AND r.is_reply = 1) as reply_count,
           (SELECT COUNT(*) FROM posts r2 WHERE r2.parent_id = p.id AND r2.deleted = 0 AND r2.is_reply = 1 AND r2.media IS NOT NULL AND r2.media != '') as image_count
    FROM posts p 
    WHERE p.deleted = 0 AND p.is_reply = 0
    ORDER BY p.updated_at DESC 
    LIMIT 150
");
$stmt->execute();
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Banner function
function renderBanner($dir) {
    $files = @glob($dir . '*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
    if (!$files) return '';
    
    $img = $files[array_rand($files)];
    return '<div style="text-align: center; margin: 20px 0;"><img src="' . htmlspecialchars($img) . '" alt="Banner" style="max-width: 100%; height: auto; border-radius: 5px;"></div>';
}

// Format text function
function formatCatalogText($text, $maxLength = 120) {
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        $text = mb_substr($text, 0, $maxLength, 'UTF-8') . '...';
    }
    
    return htmlspecialchars($text);
}

// Thumbnail function
function getThumbPath($mediaPath) {
    if (!$mediaPath || !file_exists($mediaPath)) {
        return '';
    }
    
    $filename = basename($mediaPath);
    $thumbDir = 'uploads/thumbs/';
    
    // Try WebP first
    $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
    $webpThumb = $thumbDir . 'thumb_' . $nameWithoutExt . '.webp';
    if (file_exists($webpThumb)) {
        return $webpThumb;
    }
    
    // Try original extension
    $originalThumb = $thumbDir . 'thumb_' . $filename;
    if (file_exists($originalThumb)) {
        return $originalThumb;
    }
    
    return $mediaPath;
}

$spoilerImage = 'stylesheets/spoiler.png';
$hasSpoilerImage = file_exists(__DIR__ . '/' . $spoilerImage);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>/b/ - Catálogo</title>
    
    <!-- THEME STYLESHEETS - These are the selectable CSS files -->
    <?php if (!empty($availableStyles)): ?>
        <!-- Default/current style -->
        <link rel="stylesheet" title="<?= htmlspecialchars(ucfirst($currentStyle)) ?>" href="<?= htmlspecialchars($availableStyles[$currentStyle]) ?>?v=<?= time() ?>">
        
        <!-- Alternative styles -->
        <?php foreach ($availableStyles as $styleName => $stylePath): ?>
            <?php if ($styleName !== $currentStyle): ?>
                <link rel="alternate stylesheet" title="<?= htmlspecialchars(ucfirst($styleName)) ?>" href="<?= htmlspecialchars($stylePath) ?>?v=<?= time() ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- Fallback if no stylesheets found -->
        <style>
            body { font-family: Arial, sans-serif; background: #f0f0f0; padding: 10px; }
            .board-title { text-align: center; font-size: 28px; color: #0f0c5d; }
            a { color: #0f0c5d; }
        </style>
    <?php endif; ?>

    <!-- BASE LAYOUT CSS - This ensures the structure works even without themes -->
    <style>
        /* RESET - These styles ensure the layout works */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: inherit;
            background: inherit;
            padding: 10px; 
        }
        
        /* BANNER - CENTRALIZED */
        .board-banner { 
            text-align: center; 
            margin: 20px 0; 
        }
        
        .board-banner img { 
            max-width: 100%; 
            height: auto; 
            border-radius: 5px; 
            display: inline-block; 
        }
        
        /* CATALOG GRID - This structure is independent of themes */
        #posts { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); 
            gap: 15px; 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 10px;
        }
        
        .catalogpost { 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            padding: 10px; 
            text-align: center; 
            background: white;
        }
        
        .catalogpost img { 
            width: 100%; 
            height: 120px; 
            object-fit: cover; 
            border-radius: 3px; 
        }
        
        .no-image { 
            width: 100%; 
            height: 120px; 
            background: #f5f5f5; 
            border: 1px dashed #ccc; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: #999; 
            border-radius: 3px; 
        }
        
        .catalog-text { 
            font-size: 11px; 
            margin: 8px 0; 
            max-height: 45px; 
            overflow: hidden; 
        }
        
        .thread-info { 
            display: flex; 
            justify-content: space-between; 
            font-size: 10px; 
            margin: 8px 0; 
            padding-top: 5px; 
            border-top: 1px dotted #ddd; 
        }
        
        /* STYLE SWITCHER */
        .style-switcher { 
            position: fixed; 
            bottom: 20px; 
            right: 20px; 
            z-index: 1000; 
        }
        
        .style-button { 
            display: inline-block; 
            padding: 10px 15px; 
            background: #2196F3; 
            color: white; 
            text-decoration: none; 
            border-radius: 5px; 
            cursor: pointer; 
        }
        
        .style-dropdown { 
            display: none; 
            position: absolute; 
            bottom: 100%; 
            right: 0; 
            background: white; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            margin-bottom: 10px; 
            min-width: 150px; 
        }
        
        .style-dropdown.show { 
            display: block; 
        }
        
        .style-dropdown a { 
            display: block; 
            padding: 8px 12px; 
            color: #333; 
            text-decoration: none; 
            border-bottom: 1px solid #eee; 
        }
        
        .style-dropdown a:last-child { 
            border-bottom: none; 
        }
        
        .style-dropdown a.current { 
            background: #e0e0e0; 
            font-weight: bold; 
        }
        
        /* MOBILE */
        @media (max-width: 600px) {
            #posts { 
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); 
                gap: 10px; 
            }
            
            .catalogpost img, 
            .no-image { 
                height: 100px; 
            }
        }
    </style>
</head>
<body>

<!-- TOP MESSAGE -->
<div style="text-align: center; margin: 10px 0; padding: 10px; background: #f8f8f8; border: 1px solid #ddd; border-radius: 5px;">
    [ <a href="./mod.php">Moderar</a> / <a href="../news/index.html">notícias</a> ]
</div>

<!-- BANNER - CENTRALIZED -->
<?= renderBanner('banners/') ?>

<!-- HEADER -->
<div style="text-align: center; font-size: 28px; font-weight: bold; color: #0f0c5d; margin: 15px 0 10px;">
    <?= BOARD_TITLE ?> - Catálogo
</div>

<div style="text-align: center; margin: 15px 0;">
    <a href="index.php" style="display: inline-block; margin: 0 5px; padding: 8px 15px; background: #4CAF50; color: white; text-decoration: none; border-radius: 3px;">📋 Index</a>
    <a href="catalogo.php" style="display: inline-block; margin: 0 5px; padding: 8px 15px; background: #4CAF50; color: white; text-decoration: none; border-radius: 3px;">🔄 Recarregar</a>
</div>

<div style="text-align: center; margin: 20px 0;">
    <a href="index.php" style="display: inline-block; padding: 8px 15px; background: #4CAF50; color: white; text-decoration: none; border-radius: 3px;">← Voltar para o Index</a>
</div>

<!-- CATALOG GRID -->
<div id="posts">
    <?php if (!empty($threads)): ?>
        <?php foreach ($threads as $thread): ?>
            <?php
            $threadId = $thread['id'];
            $replyCount = $thread['reply_count'] ?: 0;
            $imageCount = $thread['image_count'] ?: 0;
            $totalPosts = $replyCount + 1;
            $isSpoilered = ($thread['spoilered'] ?? 0) == 1;
            
            $message = formatCatalogText($thread['message']);
            $thumbPath = getThumbPath($thread['media'] ?? null);
            ?>
            
            <div class="catalogpost">
                <a href="index.php?post_id=<?= $threadId ?>" style="text-decoration: none; color: inherit;">
                    <?php if ($thumbPath && file_exists($thumbPath)): ?>
                        <?php if ($isSpoilered && $hasSpoilerImage): ?>
                            <img src="<?= htmlspecialchars($spoilerImage) ?>" alt="SPOILER">
                        <?php else: ?>
                            <img src="<?= htmlspecialchars($thumbPath) ?>" alt="">
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="no-image">Sem imagem</div>
                    <?php endif; ?>
                    
                    <div style="font-weight: bold; font-size: 12px; margin: 8px 0; color: #0f0c5d;">
                        <?= $totalPosts ?>R / <?= $imageCount ?>I
                    </div>
                    
                    <div class="catalog-text"><?= $message ?></div>
                    
                    <div class="thread-info">
                        <span>#<?= $threadId ?></span>
                        <span><?= date('d/m H:i', strtotime($thread['created_at'])) ?></span>
                    </div>
                    
                    <div style="margin-top: 8px;">
                        <span style="display: inline-block; padding: 5px 10px; background: #4CAF50; color: white; border-radius: 3px; font-size: 11px; width: 100%; text-align: center;">Ver tópico</span>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align: center; padding: 40px; color: red; grid-column: 1/-1;">
            Nenhum fio encontrado. <a href="index.php">Crie um primeiro fio!</a>
        </div>
    <?php endif; ?>
</div>

<!-- STYLE SWITCHER -->
<div class="style-switcher">
    <a href="#" class="style-button" onclick="toggleStyleDropdown(); return false;">🎨 <?= ucfirst($currentStyle) ?></a>
    <div class="style-dropdown" id="style-dropdown">
        <?php foreach ($availableStyles as $styleName => $stylePath): ?>
            <a href="?style=<?= $styleName ?>" class="<?= $styleName === $currentStyle ? 'current' : '' ?>">
                <?= ucfirst($styleName) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- FOOTER -->
<footer style="text-align: center; margin-top: 40px; padding: 20px; color: #666; border-top: 1px solid #ddd;">
    Recinto sendo hospedado usando o LaranjaEngine 2.0!<br>
    <small>Catálogo atualizado automaticamente</small>
</footer>

<script>
// Function to toggle style dropdown
function toggleStyleDropdown() {
    var dropdown = document.getElementById('style-dropdown');
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    var dropdown = document.getElementById('style-dropdown');
    var button = document.querySelector('.style-button');
    
    if (dropdown && button) {
        if (!button.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    }
});

// Theme switching function
function setActiveStyle(title) {
    var links = document.querySelectorAll('link[rel="stylesheet"], link[rel="alternate stylesheet"]');
    
    links.forEach(function(link) {
        if (link.getAttribute('title') === title) {
            link.disabled = false;
            link.rel = 'stylesheet';
        } else if (link.getAttribute('rel') === 'stylesheet' && link.getAttribute('title')) {
            link.disabled = true;
            link.rel = 'alternate stylesheet';
        }
    });
    
    // Update dropdown current style indicator
    document.querySelectorAll('.style-dropdown a').forEach(function(a) {
        a.classList.remove('current');
        if (a.textContent.trim() === title) {
            a.classList.add('current');
        }
    });
    
    // Update button text
    document.querySelector('.style-button').innerHTML = '🎨 ' + title;
}

// Handle style dropdown clicks
document.querySelectorAll('.style-dropdown a').forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var style = this.textContent.trim();
        var styleValue = this.getAttribute('href').split('=')[1];
        
        // Set the active style
        setActiveStyle(style);
        
        // Save to cookie
        document.cookie = "board_style=" + styleValue + "; path=/; max-age=" + (30 * 24 * 60 * 60);
        
        // Also save to session via AJAX (optional)
        fetch('?style=' + styleValue, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        
        // Close dropdown
        document.getElementById('style-dropdown').classList.remove('show');
    });
});

// Make entire catalog post clickable
document.querySelectorAll('.catalogpost').forEach(function(post) {
    post.addEventListener('click', function(e) {
        if (e.target.tagName === 'A' || e.target.closest('a')) {
            return;
        }
        var link = this.querySelector('a');
        if (link) {
            window.location.href = link.href;
        }
    });
});

// Alt+S to reveal spoilers
document.addEventListener('keydown', function(e) {
    if (e.altKey && e.key === 's') {
        e.preventDefault();
        document.querySelectorAll('.catalogpost img[alt="SPOILER"]').forEach(function(img) {
            img.style.opacity = '1';
            img.style.filter = 'none';
        });
        alert('Spoilers revelados!');
    }
});
</script>

</body>
</html>
