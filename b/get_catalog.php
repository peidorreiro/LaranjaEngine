<?php
// get_catalog.php - Enhanced version with fallback support
declare(strict_types=1);

// Include your main configuration file
require_once 'index.php'; // Change this to your main filename

// Check if this is an AJAX request or standalone
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Get all active threads with reply count
$stmt = $db->prepare("
    SELECT p.*, 
           (SELECT COUNT(*) FROM posts r WHERE r.parent_id = p.id AND r.deleted = 0 AND r.is_reply = 1 AND r.approved = 1) as reply_count,
           (SELECT COUNT(*) FROM posts r2 WHERE r2.parent_id = p.id AND r2.deleted = 0 AND r2.is_reply = 1 AND r2.media IS NOT NULL AND r2.media != '' AND r2.approved = 1) as image_count
    FROM posts p 
    WHERE p.deleted = 0 AND p.is_reply = 0 AND p.approved = 1
    ORDER BY p.sticky DESC, p.updated_at DESC 
    LIMIT 150
");
$stmt->execute();
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to format text for catalog
function formatCatalogText($text, $maxLength = 120) {
    // Remove HTML tags and line breaks
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    // Truncate if too long
    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        $text = mb_substr($text, 0, $maxLength, 'UTF-8') . '...';
    }
    
    return htmlspecialchars($text);
}

// Function to get thumbnail path (enhanced to use config constants)
function getThumbPath($mediaPath) {
    if (!$mediaPath || !file_exists($mediaPath)) {
        return '';
    }
    
    $filename = basename($mediaPath);
    $thumbDir = 'uploads/thumbs/';
    
    // Check for WebP thumbnail first (if enabled in config)
    if (USE_WEBP) {
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        $webpThumb = $thumbDir . 'thumb_' . $nameWithoutExt . '.webp';
        
        if (file_exists($webpThumb)) {
            return $webpThumb;
        }
    }
    
    // Check for original extension thumbnail
    $originalThumb = $thumbDir . 'thumb_' . $filename;
    if (file_exists($originalThumb)) {
        return $originalThumb;
    }
    
    // If no thumbnail exists, return original image
    return $mediaPath;
}

// Path to spoiler image (from config)
$spoilerImage = $webSpoilerImage;
$hasSpoilerImage = file_exists(__DIR__ . '/' . $spoilerImage);

// If it's an AJAX request, just output the HTML content
if ($isAjax) {
    // Set content type for AJAX response
    header('Content-Type: text/html; charset=utf-8');
    
    // Generate catalog HTML
    foreach ($threads as $thread) {
        $threadId = $thread['id'];
        $replyCount = $thread['reply_count'] ?: 0;
        $imageCount = $thread['image_count'] ?: 0;
        $totalPosts = $replyCount + 1; // Include OP
        $isSpoilered = ($thread['spoilered'] ?? 0) == 1;
        $isSticky = ($thread['sticky'] ?? 0) == 1;
        $isLocked = ($thread['locked'] ?? 0) == 1;
        
        $message = formatCatalogText($thread['message']);
        
        // Get thumbnail
        $thumbPath = getThumbPath($thread['media'] ?? null);
        
        echo '<div class="catalogpost">';
        
        // Make the image clickable to the thread
        echo '<a href="index.php?post_id=' . $threadId . '">';
        
        if ($thumbPath && file_exists($thumbPath)) {
            if ($isSpoilered) {
                // Show spoiler image instead of actual image
                if ($hasSpoilerImage) {
                    echo '<img src="' . htmlspecialchars($spoilerImage) . '" alt="SPOILER" style="width:100%;height:150px;object-fit:contain;background-color:#f0f0f0;border:1px solid #ddd;border-radius:2px;">';
                } else {
                    echo '<div class="no-image" style="width:100%;height:150px;background-color:#f0f0f0;border:1px dashed #b7c5d9;border-radius:2px;display:flex;align-items:center;justify-content:center;color:#707070;font-size:11px;font-style:italic;">SPOILER</div>';
                }
            } else {
                // Show normal image
                echo '<img src="' . htmlspecialchars($thumbPath) . '" alt="' . $threadId . '" style="width:100%;height:150px;object-fit:contain;background-color:#f0f0f0;border:1px solid #ddd;border-radius:2px;">';
            }
        } else {
            echo '<div class="no-image" style="width:100%;height:150px;background-color:#f0f0f0;border:1px dashed #b7c5d9;border-radius:2px;display:flex;align-items:center;justify-content:center;color:#707070;font-size:11px;font-style:italic;">Sem imagem.</div>';
        }
        
        echo '</a>';
        
        // Add icons to stats if thread is sticky or locked
        $icons = '';
        if ($isSticky) $icons .= ' 📌';
        if ($isLocked) $icons .= ' 🔒';
        
        // Add spoiler indicator to stats if image is spoilered
        $statsText = $totalPosts . ' Respostas / ' . $imageCount . ' imagens' . $icons;
        if ($isSpoilered && $thumbPath) {
            $statsText .= ' (SPOILER)';
        }
        
        echo '<b style="color:#34345c;font-size:11px;display:inline-block;margin:3px 0;padding:1px 4px;background-color:#f0f0f0;border-radius:2px;">' . $statsText . '</b>';
        echo '<div class="catalog-text" style="font-size:10px;line-height:1.3;color:#333;margin-top:5px;overflow:hidden;max-height:60px;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;">' . $message . '</div>';
        
        // Thread info footer
        echo '<div class="thread-info" style="display:flex;justify-content:space-between;align-items:center;font-size:9px;color:#707070;margin-top:5px;padding-top:5px;border-top:1px dotted #ddd;">';
        echo '<span>#' . $threadId . '</span>';
        echo '<span>' . date('d/m H:i', strtotime($thread['created_at'])) . '</span>';
        echo '</div>';
        
        // View thread button (also clickable)
        echo '<div class="view-thread" style="text-align:center;margin-top:8px;padding-top:8px;border-top:1px solid #eee;">';
        echo '<a href="index.php?post_id=' . $threadId . '" style="font-size:10px;color:#34345c;text-decoration:none;font-weight:bold;padding:3px 8px;background-color:#eef2ff;border:1px solid #b7c5d9;border-radius:3px;display:inline-block;">Responder</a>';
        echo '</div>';
        
        echo '</div>';
    }
    
    // If no threads
    if (empty($threads)) {
        echo '<div class="error" style="text-align:center;padding:40px 20px;color:#ff0000;font-weight:bold;grid-column:1/-1;background-color:#fff0f0;border:1px solid #ffcccc;border-radius:4px;">';
        echo 'Nenhum fio achado. <a href="index.php">Crie um primeiro fio!</a>';
        echo '</div>';
    }
    
    exit; // Exit after sending AJAX response
} else {
    // If not AJAX, redirect to the full catalog page
    header('Location: catalogo.php');
    exit;
}
?>
