<?php
require_once 'config.php';

// Fetch all active stores
$stores = $conn->query("SELECT id, store_name as name, branch FROM stores WHERE status='active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Store Display - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/pos/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/pos/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .selector-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
            padding: 40px;
            max-width: 600px;
            width: 90%;
        }
        .selector-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
            text-align: center;
        }
        .selector-subtitle {
            font-size: .95rem;
            color: #718096;
            text-align: center;
            margin-bottom: 30px;
        }
        .store-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 16px;
            color: white;
            text-decoration: none;
            display: block;
            transition: transform .2s, box-shadow .2s;
            cursor: pointer;
        }
        .store-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(102, 126, 234, .4);
            color: white;
        }
        .store-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .store-branch {
            font-size: .9rem;
            opacity: .9;
        }
        .store-icon {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }
        .back-link {
            text-align: center;
            margin-top: 24px;
        }
        .back-link a {
            color: white;
            text-decoration: none;
            font-size: .9rem;
            font-weight: 600;
            padding: 8px 16px;
            background: rgba(255,255,255,.2);
            border-radius: 8px;
            display: inline-block;
            transition: background .2s;
        }
        .back-link a:hover {
            background: rgba(255,255,255,.3);
        }
    </style>
</head>
<body>
    <div class="selector-container">
        <div class="selector-title">
            <i class="fas fa-tv me-2"></i>Digital Display
        </div>
        <div class="selector-subtitle">
            Select which store display to open
        </div>

        <?php if (empty($stores)): ?>
            <div style="text-align:center;padding:40px 20px;">
                <i class="fas fa-store" style="font-size:3rem;color:#cbd5e0;margin-bottom:16px;"></i>
                <p style="color:#718096;font-size:.95rem;">No stores available</p>
            </div>
        <?php else: ?>
            <?php foreach ($stores as $store): ?>
                <a href="/pos/display.php?store=<?php echo $store['id']; ?>" target="_blank" class="store-card">
                    <div class="store-name">
                        <div class="store-icon">
                            <i class="fas fa-store"></i>
                        </div>
                        <div style="flex:1;">
                            <div><?php echo htmlspecialchars($store['name']); ?></div>
                            <?php if (!empty($store['branch'])): ?>
                                <div class="store-branch">
                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($store['branch']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <i class="fas fa-arrow-right" style="font-size:1.2rem;opacity:.8;"></i>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="back-link">
            <a href="/pos/admin/advertisements.php">
                <i class="fas fa-arrow-left me-2"></i>Back to Management
            </a>
        </div>
    </div>
</body>
</html>
