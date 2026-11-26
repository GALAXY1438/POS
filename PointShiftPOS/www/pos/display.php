<?php
require_once 'config.php';

// Allow anyone to access the display (no login required)
// But respect store context if provided in URL
$store_id = isset($_GET['store']) ? (int)$_GET['store'] : 1;

// Fetch active advertisements
$ads = $conn->query("
    SELECT * FROM advertisements 
    WHERE store_id = $store_id 
    AND is_active = 1 
    AND (start_date IS NULL OR start_date <= NOW())
    AND (end_date IS NULL OR end_date >= NOW())
    ORDER BY display_order, id
")->fetch_all(MYSQLI_ASSOC);

// Fetch store info
$store = $conn->query("SELECT name, branch FROM stores WHERE id=$store_id LIMIT 1")->fetch_assoc();
$store_name = $store ? trim($store['name'] . ($store['branch'] ? ' - ' . $store['branch'] : '')) : SITE_NAME;

// Fetch display settings
$settings_raw = $conn->query("SELECT setting_key, setting_value FROM display_settings WHERE store_id=$store_id")->fetch_all(MYSQLI_ASSOC);
$settings = [];
foreach ($settings_raw as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

$transition = $settings['transition_effect'] ?? 'fade';
$show_clock = ($settings['show_clock'] ?? '1') === '1';
$show_store = ($settings['show_store_name'] ?? '1') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($store_name); ?> - Digital Display</title>
    <link rel="stylesheet" href="/pos/assets/css/fontawesome.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #000;
            color: #fff;
            overflow: hidden;
            width: 100vw;
            height: 100vh;
        }
        
        /* Main Display Area */
        #display-container {
            position: relative;
            width: 100%;
            height: 100%;
        }
        
        /* Slide */
        .slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 1s ease-in-out;
        }
        
        .slide.active {
            opacity: 1;
            z-index: 1;
        }
        
        .slide.fade-out {
            opacity: 0;
        }
        
        /* Media content */
        .slide video,
        .slide img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .slide video {
            width: 100%;
            height: 100%;
        }
        
        /* Text slide */
        .text-slide {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 60px;
            font-weight: 600;
            line-height: 1.4;
        }
        
        /* Header bar */
        #header-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(180deg, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0) 100%);
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
        }
        
        #store-name {
            font-size: 1.8rem;
            font-weight: 700;
            color: #fff;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }
        
        #clock {
            font-size: 2.5rem;
            font-weight: 300;
            font-family: 'Courier New', monospace;
            color: #fff;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }
        
        /* Footer */
        #footer-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(0deg, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0) 100%);
            padding: 15px 40px;
            text-align: center;
            z-index: 100;
            font-size: 0.9rem;
            color: rgba(255,255,255,0.7);
        }
        
        /* Loading state */
        #loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #000;
            z-index: 200;
        }
        
        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255,255,255,0.1);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* No content message */
        #no-content {
            display: none;
            text-align: center;
            padding: 60px;
        }
        
        #no-content i {
            font-size: 5rem;
            color: rgba(255,255,255,0.3);
            margin-bottom: 20px;
        }
        
        #no-content h2 {
            font-size: 1.8rem;
            font-weight: 300;
            margin-bottom: 10px;
        }
        
        #no-content p {
            color: rgba(255,255,255,0.6);
            font-size: 1.1rem;
        }
    </style>
</head>
<body>

<!-- Loading -->
<div id="loading">
    <div class="spinner"></div>
    <p style="margin-top:20px;color:rgba(255,255,255,0.7);">Loading content...</p>
</div>

<!-- Header -->
<?php if ($show_store || $show_clock): ?>
<div id="header-bar">
    <?php if ($show_store): ?>
    <div id="store-name"><?php echo htmlspecialchars($store_name); ?></div>
    <?php else: ?>
    <div></div>
    <?php endif; ?>
    
    <?php if ($show_clock): ?>
    <div id="clock"></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Display Container -->
<div id="display-container">
    <?php if (empty($ads)): ?>
    <div id="no-content">
        <i class="fas fa-tv"></i>
        <h2>No content available</h2>
        <p>Add advertisements in the admin panel to start displaying</p>
    </div>
    <?php else: ?>
        <?php foreach ($ads as $index => $ad): ?>
        <div class="slide" data-index="<?php echo $index; ?>" data-duration="<?php echo $ad['duration']; ?>" data-type="<?php echo $ad['type']; ?>" data-youtube="<?php echo htmlspecialchars($ad['youtube_url'] ?? ''); ?>">
            <?php if ($ad['type'] === 'youtube' && $ad['youtube_url']): ?>
                <div style="width:100%; height:100%; position:relative;">
                    <iframe 
                        src="https://www.youtube.com/embed/<?php echo htmlspecialchars($ad['youtube_url']); ?>?autoplay=1&mute=1&controls=0&showinfo=0&rel=0&modestbranding=1&playsinline=1&enablejsapi=1"
                        style="width:100%; height:100%; border:none;"
                        allow="autoplay; fullscreen"
                        allowfullscreen>
                    </iframe>
                </div>
                
            <?php elseif ($ad['type'] === 'video' && $ad['file_path']): ?>
                <video src="<?php echo htmlspecialchars($ad['file_path']); ?>" muted></video>
                
            <?php elseif ($ad['type'] === 'image' && $ad['file_path']): ?>
                <img src="<?php echo htmlspecialchars($ad['file_path']); ?>" alt="<?php echo htmlspecialchars($ad['title']); ?>">
                
            <?php elseif ($ad['type'] === 'text'): ?>
                <div class="text-slide" style="background:<?php echo htmlspecialchars($ad['background_color']); ?>; color:<?php echo htmlspecialchars($ad['text_color']); ?>; font-size:<?php echo htmlspecialchars($ad['font_size']); ?>;">
                    <?php echo nl2br(htmlspecialchars($ad['content'])); ?>
                </div>
                
            <?php elseif ($ad['type'] === 'html'): ?>
                <div style="width:100%; height:100%;">
                    <?php echo $ad['content']; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Footer -->
<div id="footer-bar">
    Powered by <?php echo SITE_NAME; ?> Digital Signage
</div>

<script>
const slides = document.querySelectorAll('.slide');
const hasContent = slides.length > 0;
let currentSlide = 0;
let slideTimeout;

// Hide loading
setTimeout(() => {
    document.getElementById('loading').style.display = 'none';
    if (!hasContent) {
        document.getElementById('no-content').style.display = 'block';
    }
}, 1000);

// Update clock
<?php if ($show_clock): ?>
function updateClock() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    document.getElementById('clock').textContent = `${hours}:${minutes}:${seconds}`;
}
updateClock();
setInterval(updateClock, 1000);
<?php endif; ?>

// Slideshow logic
function showSlide(index) {
    if (!hasContent) return;
    
    // Remove active from all
    slides.forEach(s => {
        s.classList.remove('active');
        // Stop any YouTube videos
        const iframe = s.querySelector('iframe');
        if (iframe && iframe.src.includes('youtube.com')) {
            iframe.src = iframe.src; // Reload to stop
        }
    });
    
    const slide = slides[index];
    const type = slide.dataset.type;
    const duration = parseInt(slide.dataset.duration) * 1000;
    
    slide.classList.add('active');
    
    // Handle YouTube video
    if (type === 'youtube') {
        const iframe = slide.querySelector('iframe');
        if (iframe) {
            // Reload iframe to start video
            const src = iframe.src;
            iframe.src = src;
        }
        scheduleNext(duration);
    }
    // Handle regular video
    else if (type === 'video') {
        const video = slide.querySelector('video');
        if (video) {
            video.currentTime = 0;
            video.play().catch(() => {
                scheduleNext(duration);
            });
            
            video.onended = () => {
                nextSlide();
            };
        }
    } else {
        // For non-video, schedule next slide
        scheduleNext(duration);
    }
}

function scheduleNext(duration) {
    clearTimeout(slideTimeout);
    slideTimeout = setTimeout(nextSlide, duration);
}

function nextSlide() {
    currentSlide = (currentSlide + 1) % slides.length;
    showSlide(currentSlide);
}

// Start slideshow
if (hasContent) {
    showSlide(0);
}

// Auto-refresh every 5 minutes to get new content
setInterval(() => {
    location.reload();
}, 5 * 60 * 1000);

// Prevent sleep
let wakeLock = null;
if ('wakeLock' in navigator) {
    navigator.wakeLock.request('screen').then(lock => {
        wakeLock = lock;
    });
}
</script>

</body>
</html>
