<?php
require_once __DIR__ . '/../app/models/Campaign.php';
require_once __DIR__ . '/../app/config.php';

$slug = $_GET['slug'] ?? '';
$campaign = Campaign::findBySlug($slug);

if (!$campaign) {
    http_response_code(404);
    echo "Campanha não encontrada.";
    exit;
}

function formatMoney($value) {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

$percent = 0;
if ($campaign['goal_amount'] > 0) {
    $percent = min(100, ($campaign['raised_amount'] / $campaign['goal_amount']) * 100);
}

function getInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        }
    }
    return mb_substr($initials, 0, 2);
}

function isMobile() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|BB10|IEMobile|Opera Mini|webOS|Kindle|Silk|PlayBook/i', $ua);
}
$isMobile = isMobile();

// >>> ADDED: utility to detect video files by extension
function isVideoPath($p) {
    if (empty($p)) return false;
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    return in_array($ext, ['mp4','webm','ogg','mov','mkv','avi','flv','wmv']);
}


// ADICIONAR: coleta de UTMs para propagar ao checkout
$utmKeys = [
  'utm_source','utm_campaign','utm_medium','utm_content','utm_term','utm_id',
  'fbclid','fbc','fbp','ttclid','src','xcod','external_id','currency'
];
$capturedUtms = [];
foreach ($utmKeys as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') {
        $capturedUtms[$k] = $_GET[$k];
    }
}
$utmQuery = http_build_query($capturedUtms);
$checkoutUrl = 'checkout.php?slug=' . urlencode($campaign['slug']) . ($utmQuery ? '&' . $utmQuery : '');

/* VALORES DINÂMICOS PARA CORAÇÕES E APOIADORES */
$hearts = isset($campaign['hearts_received']) ? (int)$campaign['hearts_received'] : 0;
$supporters = isset($campaign['supporters']) ? (int)$campaign['supporters'] : 0;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <?php if (!empty($campaign['tiktok_pixel_id'])): ?>
    <!-- TikTok Pixel Base Code -->
    <script>
      !function (w, d, t) {
        w.TiktokAnalyticsObject = t;
        var ttq = w[t] = w[t] || [];
        ttq.methods = ["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"];
        ttq.setAndDefer = function(target, method){
          target[method] = function(){
            target.push([method].concat(Array.prototype.slice.call(arguments, 0)));
          };
        };
        for (var i = 0; i < ttq.methods.length; i++) ttq.setAndDefer(ttq, ttq.methods[i]);
        ttq.instance = function(id){
          var inst = ttq._i[id] || [];
          for (var n = 0; n < ttq.methods.length; n++) ttq.setAndDefer(inst, ttq.methods[n]);
          return inst;
        };
        ttq.load = function(id, opts){
          var src = "https://analytics.tiktok.com/i18n/pixel/events.js";
          ttq._i = ttq._i || {};
          ttq._i[id] = [];
          ttq._i[id]._u = src;
          ttq._t = ttq._t || {};
          ttq._t[id] = +new Date();
          ttq._o = ttq._o || {};
          ttq._o[id] = opts || {};
          var s = document.createElement("script");
          s.type = "text/javascript";
          s.async = true;
          s.src = src + "?sdkid=" + id + "&lib=" + t;
          var x = document.getElementsByTagName("script")[0];
          x.parentNode.insertBefore(s, x);
        };

        function logTikTokClientEvent(eventName, payload) {
          var bodyObj = {
            source: 'campaign',
            pixel_id: <?= json_encode((string)$campaign['tiktok_pixel_id']) ?>,
            event: eventName,
            payload: payload || null,
            page: window.location.href,
            ts_client: new Date().toISOString()
          };
          var body = JSON.stringify(bodyObj);
          try {
            if (navigator.sendBeacon) {
              navigator.sendBeacon('api/tiktok_pixel_log.php', new Blob([body], { type: 'application/json' }));
              return;
            }
          } catch (e) {}
          try {
            fetch('api/tiktok_pixel_log.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: body,
              keepalive: true
            });
          } catch (e) {}
        }

        ttq.load(<?= json_encode((string)$campaign['tiktok_pixel_id']) ?>);
        ttq.page();
        logTikTokClientEvent('PageView', { source: 'ttq.page' });
        console.log('[TT PIXEL] Base code carregado (campaign)', { pixel_id: <?= json_encode((string)$campaign['tiktok_pixel_id']) ?> });
      }(window, document, 'ttq');
    </script>
  <?php endif; ?>
  <title><?= htmlspecialchars($campaign['title']) ?> | Minha Vaquinha</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
  <link rel="stylesheet" href="css/style.css">
  <link href="https://fonts.googleapis.com/css?family=Lato:400,700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css?family=Montserrat:700&display=swap" rel="stylesheet">
  <?php if (!empty($campaign['facebook_pixel_id'])): ?>
    <!-- Facebook Pixel Code -->
    <script>
      !function(f,b,e,v,n,t,s)
      {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};
      if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
      n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t,s)}(window, document,'script',
      'https://connect.facebook.net/en_US/fbevents.js');
      fbq('init', '<?= htmlspecialchars($campaign['facebook_pixel_id']) ?>');
      fbq('track', 'PageView');
    </script>
    <noscript>
      <img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=<?= htmlspecialchars($campaign['facebook_pixel_id']) ?>&ev=PageView&noscript=1"/>
    </noscript>
  <?php endif; ?>
  <style>
    body, .vakinha-header, .campaign-card, .sidebar-card, .footer, .faq-item, .pix-block, .campaign-tabs, .campaign-page-main, .logo-text, .nav-item, .account-link, .btn-vakinha, .btn-primary, .btn-outline {
      font-family: 'Lato', Arial, sans-serif !important;
      color: #282828;
    }
    .vakinha-header .container * {
      color: #282828;
    }
    .campaign-card *,
    .sidebar-card *,
    .footer *,
    .faq-section *,
    .pix-block *,
    .campaign-tabs * {
      color: #282828;
    }
    /* HEADER STYLE */
    .vakinha-header {
      background: #fff;
      border-bottom: 1px solid #eee;
      margin-bottom: 0;
      padding: 0;
      height: 96px; /* antes 88px */
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .vakinha-header .container {
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 96px; /* antes 88px */
    }
    .vakinha-header .logo-area {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .vakinha-header .logo-img {
      height: 48px;
      width: auto;
      vertical-align: middle;
    }
    .vakinha-header .logo-text {
      color: #00c853;
      font-weight: bold;
      font-size: 1.7em;
      letter-spacing: -1px;
      margin-left: 2px;
      font-family: 'Arial', sans-serif;
    }
    .vakinha-header nav {
      display: flex;
      align-items: center;
      gap: 28px;
      flex: 1;
      margin-left: 40px;
    }
    .vakinha-header .nav-item {
      font-size: 1.08em;
      color: #222;
      font-weight: bold; /* alterado para negrito */
      cursor: pointer;
      position: relative;
      padding: 0 6px;
      transition: color .2s;
    }
    .vakinha-header .nav-item:hover {
      color: #00c853;
    }
    .vakinha-header .nav-item .arrow {
      display: inline-block;
      vertical-align: middle;
      margin-left: 3px;
      width: 18px;
      height: 18px;
    }
    .vakinha-header .search-icon {
      width: 22px;
      height: 22px;
      vertical-align: middle;
      margin-left: 2px;
      margin-bottom: 2px;
    }
    .vakinha-header .nav-right {
      display: flex;
      align-items: center;
      gap: 22px;
    }
    .vakinha-header .search-box {
      display: flex;
      align-items: center;
      gap: 4px;
      color: #00c853;
      font-size: 1.08em;
      cursor: pointer;
      font-weight: bold; /* negrito */
    }
    .vakinha-header .search-label {
      display: none;
    }
    .vakinha-header .account-link {
      color: #00c853;
      font-weight: bold; /* negrito */
      font-size: 1.08em;
      text-decoration: none;
      transition: color .2s;
    }
    .vakinha-header .account-link:hover {
      color: #009e3c;
      text-decoration: underline;
    }
    .vakinha-header .btn-vakinha {
      background: #00c853;
      color: #fff;
      font-weight: bold; /* já está negrito */
      border: none;
      border-radius: 8px;
      padding: 10px 28px;
      font-size: 1.08em;
      cursor: pointer;
      transition: background .2s;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .vakinha-header .btn-vakinha:hover {
      background: #009e3c;
    }
    .campaign-page-main {
      display: flex;
      gap: 32px;
      max-width: 1100px;
      margin: 0 auto;
    }
    .campaign-card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.07);
      padding: 0 0 24px 0;
      flex: 2;
      margin-bottom: 30px;
      margin-top: 36px; /* adiciona espaço do topo no desktop */
    }
    @media (max-width: 900px) {
      .campaign-card {
        width: 100%;
        max-width: 600px;
        margin-bottom: 24px;
        margin-top: 24px !important; /* espaço menor no mobile/tablet */
      }
    }
    @media (max-width: 600px) {
      .vakinha-header {
        height: 80px; /* antes 70/56/variável */
      }
      .vakinha-header .container {
        height: 80px;
      }
      .campaign-card {
        width: calc(100% - 32px);
        margin: 16px auto 24px;
        border-radius: 18px;
        box-shadow: 0 4px 14px rgba(0,0,0,0.08);
        overflow: hidden;
        background: #fff;
        margin-top: 48px !important; /* aumenta ainda mais a distância da foto ao header no mobile */
      }
      .sidebar-card-mobile {
        width: 100%;
        margin: 18px 18px 0 18px;
        border: 1px solid #f0f0f0;
        background: #fafafa;
        border-radius: 14px;
        box-shadow: none;
        padding: 18px 16px;
        margin-top: 48px !important; /* aumenta ainda mais a distância do bloco de arrecadação ao header no mobile */
      }
    }
    .campaign-card .cover-img {
      width: 100%;
      height: 320px;
      object-fit: cover;
      border-radius: 12px 12px 0 0;
      background: #eee;
      display: block;
    }
    .campaign-card .cover-video {
      width: 100%;
      height: 320px;
      object-fit: cover;
      border-radius: 12px 12px 0 0;
      background: #000;
      display: block;
    }
    .campaign-card .category-location-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin: 18px 24px 0 24px;
    }
    .campaign-card .category {
      font-size: 13px;
      color: #888;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-weight: 500;
    }
    .campaign-card .location {
      display: flex;
      align-items: center;
      font-size: 13px;
      color: #888;
      font-weight: 500;
      gap: 5px;
    }
    .campaign-card .location-icon {
      width: 16px;
      height: 16px;
      display: inline-block;
      vertical-align: middle;
      margin-bottom: 2px;
    }
    .campaign-card h1 {
      font-size: 2em;
      margin: 8px 0 0 24px;
      font-weight: bold;
      color: #222;
    }
    .campaign-card .subtitle {
      margin: 8px 0 0 24px;
      color: #555;
      font-size: 1.1em;
    }
    .campaign-card .desc {
      margin: 18px 24px 0 24px;
      color: #444;
      line-height: 1.6;
      font-size: 1.08em;
    }
    .campaign-card .info-row {
      margin: 18px 24px 0 24px;
      font-size: 0.98em;
      color: #666;
    }
    .campaign-tabs {
      display: flex;
      gap: 32px;
      margin: 28px 0 0 24px;
      border-bottom: 2px solid #eee;
    }
    .campaign-tabs .tab {
      padding: 8px 0;
      font-weight: bold;
      color: #00c853;
      border-bottom: 2px solid #00c853;
      cursor: pointer;
    }
    .campaign-tabs .tab.inactive {
      color: #888;
      border-bottom: none;
      font-weight: normal;
    }
    .faq-section {
      margin: 32px 24px 0 24px;
      background: #fafafa;
      border-radius: 8px;
      padding: 18px 20px;
    }
    .faq-section h2 {
      font-size: 1.2em;
      margin-bottom: 14px;
      color: #222;
      font-weight: bold;
    }
    .faq-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .faq-item {
      background: #fff;
      border-radius: 7px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.04);
      padding: 12px 18px;
      font-size: 1em;
      cursor: pointer;
      border: none;
      outline: none;
      transition: box-shadow .2s;
    }
    .faq-item:hover {
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .pix-block {
      margin: 24px 24px 0 24px;
      background: #ededed;
      border-radius: 8px;
      padding: 18px 20px;
      font-size: 1.08em;
      color: #222;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .pix-block .pix-icon {
      font-size: 2em;
      color: #222;
    }
    .pix-block .pix-key {
      font-weight: bold;
      color: #222;
      margin-left: 6px;
    }
    .pix-block .pix-copy {
      margin-left: 12px;
      font-size: 0.95em;
      color: #00c853;
      cursor: pointer;
      border: none;
      background: none;
    }
    .campaign-sidebar {
      flex: 1;
      min-width: 320px;
      max-width: 370px;
      margin-top: 0;
    }
    .sidebar-card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.07);
      padding: 22px 24px;
      margin-bottom: 18px;
      text-align: center;
    }
    .sidebar-card .progress-bar {
      width: 100%;
      height: 8px;
      background: #eee;
      border-radius: 4px;
      overflow: hidden;
      margin-bottom: 18px;
      margin-top: 0;
    }
    .sidebar-card h3 {
      font-size: 1.15em;
      color: #222;
      margin-bottom: 8px;
      margin-top: 0;
      font-weight: bold;
      text-align: left;
    }
    .sidebar-card .amount {
      font-size: 2em;
      color: #00c853;
      font-weight: bold;
      margin-bottom: 2px;
      text-align: left;
    }
    .sidebar-card .goal {
      color: #888;
      font-size: 1em;
      margin-bottom: 10px;
      text-align: left;
    }
    .sidebar-card .stats-block {
      background: #f3fff3;
      border-radius: 6px;
      padding: 12px 16px;
      margin-bottom: 18px;
      margin-top: 10px;
      font-size: 1.08em;
      color: #222;
      display: flex;
      flex-direction: column;
      gap: 7px;
      align-items: stretch;
    }
    .sidebar-card .stats-row {
      display: flex;
      justify-content: space-between; /* números no canto direito */
      align-items: center;
      width: 100%;
      gap: 10px;
    }
    .sidebar-card .stats-label {
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .sidebar-card .stats-label .heart {
      color: #00c853;
      font-size: 1.1em;
      margin-left: 2px;
    }
    .sidebar-card .stats-value {
      font-weight: bold;
      font-size: 1em;
      color: #282828;
      min-width: 38px;
      text-align: right; /* alinhado à direita */
    }
    .sidebar-card .btn-primary {
      background: #00c853;
      color: #fff;
      border: none;
      border-radius: 7px;
      padding: 14px 0;
      font-size: 1.15em;
      width: 100%;
      margin-bottom: 12px;
      cursor: pointer;
      font-weight: bold;
      transition: background .2s;
      font-family: 'Montserrat', Arial, sans-serif !important;
      font-weight: 700 !important;
    }
    .sidebar-card .btn-primary:hover {
      background: #009e3c;
    }
    .sidebar-card .btn-outline {
      background: #fff;
      border: 2px solid #282828;
      color: #282828;
      border-radius: 7px;
      padding: 14px 0;
      font-size: 1.15em;
      width: 100%;
      cursor: pointer;
      font-weight: bold;
      transition: background .2s, color .2s;
      margin-bottom: 18px;
      font-family: 'Montserrat', Arial, sans-serif !important;
      font-weight: 700 !important;
    }
    .sidebar-card .btn-outline:hover {
      background: #3A3A3A;
      color: #fff;
    }
    .sidebar-card .donation-protected {
      display: flex;
      align-items: center;
      gap: 8px;
      background: #fff;
      border-radius: 6px;
      padding: 10px 0;
      margin-bottom: 10px;
      justify-content: flex-start;
      font-size: 1em;
      border-bottom: 1px solid #f3f3f3;
    }
    .sidebar-card .donation-icon {
      background: #00c853;
      color: #fff;
      border-radius: 50%;
      width: 28px;
      height: 28px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2em;
      margin-left: 8px;
    }
    .sidebar-card .donation-label {
      color: #00c853;
      font-weight: bold;
      font-size: 1em;
      margin-left: 2px;
    }
    .sidebar-card .user-block {
      background: #f7f7f7;
      border-radius: 7px;
      padding: 12px 14px;
      font-size: 1em;
      color: #222;
      display: flex;
      align-items: center;
      gap: 12px;
      margin-top: 10px;
      margin-bottom: 0;
      justify-content: flex-start;
    }
    .sidebar-card .user-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: #ddd;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1em;
      color: #555;
      font-weight: bold;
    }
    .sidebar-card .user-info-text {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
    }
    .sidebar-card .user-name {
      font-weight: bold;
      font-size: 1.08em;
      margin-bottom: 2px;
    }
    .sidebar-card .user-date {
      font-size: 0.98em;
      color: #888;
    }
    @media (max-width: 900px) {
      .vakinha-header .container {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }
      .vakinha-header nav {
        display: none;
      }
      .vakinha-header .nav-right {
        gap: 10px;
      }
      .vakinha-header .search-box {
        display: none;
      }
      .vakinha-header .menu-mobile {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        background: #00c853;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        cursor: pointer;
        border: none;
        outline: none;
        transition: background .2s;
      }
      .vakinha-header .menu-mobile:hover {
        background: #009e3c;
      }
      .vakinha-header .menu-icon {
        width: 22px;
        height: 22px;
        fill: #fff;
      }
      .campaign-page-main {
        flex-direction: column;
        align-items: center;
        padding: 0 16px;
      }
      .campaign-card {
        width: 100%;
        max-width: 600px;
        margin-bottom: 24px;
        margin-top: 24px !important; /* espaço menor no mobile/tablet */
      }
      .sidebar-card {
        width: 100%;
        max-width: 600px;
        margin: 0 auto;
      }
      .footer-content {
        flex-direction: column;
        align-items: center;
        padding: 24px 16px;
      }
      .footer-col {
        margin-left: 0;
        margin-bottom: 24px;
        text-align: center;
      }
      .footer-col h4 {
        margin-bottom: 12px;
      }
      .footer-bottom {
        font-size: 0.9em;
        padding: 12px 0;
      }
    }
    /* Estilo da barra fixa de ação no mobile */
    .fixed-action-bar {
      display: none;
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: #fff;
      border-top: 1px solid #eee;
      padding: 12px 16px;
      box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
      z-index: 1000;
      align-items: center;
      gap: 10px;
    }
    .fixed-action-bar .btn-primary,
    .fixed-action-bar .btn-outline {
      flex: 1;
      padding: 14px;
      font-size: 1.1em;
      font-weight: bold;
      border-radius: 7px;
      cursor: pointer;
      transition: background .2s;
    }
    .fixed-action-bar .btn-primary {
      background: #00c853;
      color: #fff;
      border: none;
    }
    .fixed-action-bar .btn-primary:hover {
      background: #009e3c;
    }
    .fixed-action-bar .btn-outline {
      background: #f3f3f3;
      color: #282828;
      border: 2px solid #282828;
    }
    .fixed-action-bar .btn-outline:hover {
      background: #e0e0e0;
    }
    .fixed-action-bar .donation-protected {
      display: flex;
      align-items: center;
      gap: 8px;
      background: #00c853;
      color: #fff;
      border-radius: 6px;
      padding: 10px 14px;
      font-size: 1em;
      flex: 1;
    }
    .fixed-action-bar .donation-icon {
      background: #fff;
      color: #00c853;
      border-radius: 50%;
      width: 28px;
      height: 28px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2em;
    }
    @media (max-width: 900px) {
      .fixed-action-bar {
        display: flex;
      }
    }
    .fab-btn-box {
      background: #fff;
      border-radius: 8px;
      padding: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      flex: 1;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    /* Novo estilo para o grupo de botões */
    .fab-btn-group {
      display: flex;
      width: 100%;
      border-radius: 8px;
      overflow: hidden;
      background: #fff;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      gap: 0;
    }
    .fab-btn-group .btn-primary,
    .fab-btn-group .btn-outline {
      flex: 1;
      margin: 0; /* Neutraliza margens antigas */
    }
    @media (max-width: 600px) {
      .campaign-card {
        width: calc(100% - 32px);
        margin: 16px auto 24px;
        border-radius: 18px;
        box-shadow: 0 4px 14px rgba(0,0,0,0.08);
        overflow: hidden;
        background: #fff;
        margin-top: 48px !important; /* aumenta ainda mais a distância da foto ao header no mobile */
      }
      .sidebar-card-mobile {
        width: 100%;
        margin: 18px 18px 0 18px;
        border: 1px solid #f0f0f0;
        background: #fafafa;
        border-radius: 14px;
        box-shadow: none;
        padding: 18px 16px;
        margin-top: 48px !important; /* aumenta ainda mais a distância do bloco de arrecadação ao header no mobile */
      }
    }
  </style>
  <script>
    function copyPixKey() {
      const key = document.getElementById('pix-key-value').innerText;
      navigator.clipboard.writeText(key);
      const btn = document.getElementById('pix-copy-btn');
      btn.innerText = 'Copiado!';
      setTimeout(() => { btn.innerText = 'Copiar'; }, 1500);
    }
  </script>
</head>
<body class="<?= $isMobile ? 'is-mobile' : 'is-desktop' ?>">
  <script>
    // Força a classe is-mobile no <body> se for mobile, SEM depender do PHP
    (function(){
      var meta = document.querySelector('meta[name=viewport]');
      if (!meta) {
        meta = document.createElement('meta');
        meta.name = "viewport";
        meta.content = "width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no";
        document.head.appendChild(meta);
      }
      function isMobileDevice() {
        // Verifica user agent OU se é touch device OU largura de tela pequena
        var ua = navigator.userAgent || '';
        var isMobileUA = /Mobile|Android|iPhone|iPad|iPod|BlackBerry|BB10|IEMobile|Opera Mini|webOS|Kindle|Silk|PlayBook/i.test(ua);
        var isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        var isSmallScreen = window.innerWidth <= 700;
        return isMobileUA || isTouch || isSmallScreen;
      }
      var body = document.body;
      if (isMobileDevice()) {
        body.classList.remove('is-desktop');
        body.classList.add('is-mobile');
      } else {
        body.classList.remove('is-mobile');
        body.classList.add('is-desktop');
      }
    })();
  </script>
  <div class="vakinha-header">
    <div class="container">
      <div class="logo-area">
        <img src="logo.png" alt="Vakinha" class="logo-img">
        <!-- nome removido do header conforme solicitado -->
      </div>

      <nav>
        <span class="nav-item">
          Como ajudar
          <span class="arrow">
            <!-- SVG seta arredondada -->
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
              <path d="M5 7l4 4 4-4" stroke="#282828" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </span>
        <span class="nav-item">
          Descubra
          <span class="arrow">
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
              <path d="M5 7l4 4 4-4" stroke="#282828" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </span>
        <span class="nav-item">
          Como funciona
          <span class="arrow">
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
              <path d="M5 7l4 4 4-4" stroke="#282828" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </span>
      </nav>

      <div class="nav-right">
        <button class="search-box" aria-label="Buscar">
          <span class="search-label">Buscar</span>
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="11" cy="11" r="7" stroke="#00c853" stroke-width="2"/>
            <line x1="16.5" y1="16.5" x2="21" y2="21" stroke="#00c853" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
        <a href="#" class="account-link">Minha conta</a>
        <button class="btn-vakinha">Faz uma vaquinha!</button>
        <!-- botão menu (apenas mobile aparece) -->
        <button class="menu-mobile" aria-label="Menu">
          <svg class="menu-icon" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="6" y="9" width="20" height="2.5" rx="1.2" fill="#282828"/>
            <rect x="6" y="15" width="20" height="2.5" rx="1.2" fill="#282828"/>
            <rect x="6" y="21" width="20" height="2.5" rx="1.2" fill="#282828"/>
          </svg>
        </button>
      </div>
    </div>
  </div>
  <div class="header-spacer"></div>
  <div class="campaign-page-main">
    <div class="campaign-card">
      <?php if (!empty($campaign['cover_image']) && isVideoPath($campaign['cover_image'])): ?>
        <!-- Renderiza vídeo quando o arquivo for vídeo -->
        <video class="cover-video" src="<?= htmlspecialchars($campaign['cover_image']) ?>" controls playsinline preload="metadata"></video>
      <?php elseif ($campaign['cover_image']): ?>
        <img class="cover-img" src="<?= htmlspecialchars($campaign['cover_image']) ?>" alt="Imagem da campanha">
      <?php else: ?>
        <div class="cover-img" style="background:#eee;display:flex;align-items:center;justify-content:center;color:#888;">Sem imagem</div>
      <?php endif; ?>
      <div class="category-location-row">
        <span class="category"><?= htmlspecialchars($campaign['category']) ?></span>
        <?php if ($campaign['city']): ?>
          <span class="location">
            <span class="location-icon">
              <!-- SVG pin de localização -->
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M8 15C8 15 13 9.5 13 6.5C13 3.73858 10.7614 1.5 8 1.5C5.23858 1.5 3 3.73858 3 6.5C3 9.5 8 15 8 15Z" stroke="#888" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="8" cy="6.5" r="2" stroke="#888" stroke-width="1.2"/>
              </svg>
            </span>
            <?= htmlspecialchars($campaign['city']) ?><?= $campaign['state'] ? ' / ' . htmlspecialchars($campaign['state']) : '' ?>
          </span>
        <?php endif; ?>
      </div>
      <h1><?= htmlspecialchars($campaign['title']) ?></h1>
      <?php if (isMobile()): ?>
      <!-- Bloco de arrecadação para mobile, logo após o título -->
      <div class="sidebar-card sidebar-card-mobile">
        <div class="progress-bar">
          <div class="fill" style="width: <?= $percent ?>%;"></div>
        </div>
        <h3>Arrecadado</h3>
        <div class="amount"><?= formatMoney($campaign['raised_amount']) ?></div>
        <div class="goal">de <?= formatMoney($campaign['goal_amount']) ?></div>
        <div class="stats-block">
          <div class="stats-row">
            <span class="stats-label">Corações Recebidos <span class="heart">&#10084;</span></span>
            <span class="stats-value"><?= number_format($hearts) ?></span>
          </div>
          <div class="stats-row">
            <span class="stats-label">Apoiadores</span>
            <span class="stats-value"><?= number_format($supporters) ?></span>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!empty($campaign['subtitle'])): ?>
        <div class="subtitle"><?= nl2br(htmlspecialchars($campaign['subtitle'])) ?></div>
      <?php endif; ?>
      <div class="desc"><?= nl2br(htmlspecialchars($campaign['description'])) ?></div>
      <div class="campaign-tabs">
        <span class="tab">Sobre</span>
        <span class="tab inactive">Atualizações</span>
        <span class="tab inactive">Quem ajudou</span>
        <span class="tab inactive">Vakinhas Premiadas</span>
        <span class="tab inactive">Selos recebidos</span>
      </div>
      <!-- agora rolável no mobile (overflow-x:auto) -->
      <div class="faq-section">
        <h2>Tudo o que você precisa saber sobre o Vakinha</h2>
        <div class="faq-list">
          <button class="faq-item">Como ajudar <?= htmlspecialchars($campaign['subtitle'] ?? 'essa campanha') ?>?</button>
          <button class="faq-item">Quando a vaquinha foi criada?</button>
          <button class="faq-item">Qual a meta da vaquinha <?= htmlspecialchars($campaign['title']) ?>?</button>
          <button class="faq-item">Como posso ajudar via chave PIX?</button>
          <button class="faq-item">Quanto a vaquinha já arrecadou?</button>
        </div>
      </div>
    </div>
    <aside class="campaign-sidebar">
      <div class="sidebar-card sidebar-card-desktop">
        <div class="progress-bar">
          <div class="fill" style="width: <?= $percent ?>%;"></div>
        </div>
        <h3>Arrecadado</h3>
        <div class="amount"><?= formatMoney($campaign['raised_amount']) ?></div>
        <div class="goal">de <?= formatMoney($campaign['goal_amount']) ?></div>
        <div class="stats-block">
          <div class="stats-row">
            <span class="stats-label">Corações Recebidos <span class="heart">&#10084;</span></span>
            <span class="stats-value"><?= number_format($hearts) ?></span>
          </div>
          <div class="stats-row">
            <span class="stats-label">Apoiadores</span>
            <span class="stats-value"><?= number_format($supporters) ?></span>
          </div>
        </div>
        <!-- Botões e selo: continuam aqui para desktop, mas são ocultados no mobile via CSS -->
        <a href="<?= htmlspecialchars($checkoutUrl) ?>" class="btn-primary" style="text-decoration:none;display:block;text-align:center;">Quero Ajudar</a>
        <button type="button" class="btn-outline" onclick="sharePage()">Compartilhar</button>
        <div class="donation-protected">
          <img src="do.png" alt="Doação Protegida" class="doacao-protegida-img">
        </div>
        <div class="user-block">
          <span class="user-avatar">
            <?= getInitials($campaign['subtitle'] ?? 'Organizador') ?>
          </span>
          <span class="user-info-text">
            <span class="user-name">
              <?= htmlspecialchars($campaign['subtitle'] ?? 'Organizador') ?>
            </span>
            <span class="user-date">
              Ativo(a) desde <?= strftime('%B/%Y', strtotime($campaign['created_at'])) ?>
            </span>
          </span>
        </div>
      </div>
    </aside>
  </div>
  <!-- Barra fixa de ação no mobile -->
  <div class="fixed-action-bar">
    <div class="donation-protected" style="display:flex;justify-content:center;align-items:flex-start;">
      <img src="do.png" alt="Doação Protegida" class="doacao-protegida-img" style="height:240px;width:auto;margin-top:10px;">
    </div>
    <!-- Fundo único para os dois botões -->
    <div class="fab-btn-group">
      <a href="<?= htmlspecialchars($checkoutUrl) ?>" class="btn-primary action-btn" style="text-decoration:none;">Quero Ajudar</a>
      <button type="button" class="btn-outline action-btn" onclick="sharePage()">Compartilhar</button>
    </div>
  </div>
  <!-- ADICIONAR: script para reforçar UTMs em qualquer novo link para checkout -->
  <script>
    (function(){
      var utmQuery = <?= json_encode($utmQuery) ?>;
      if (!utmQuery) return;
      var slug = <?= json_encode($campaign['slug']) ?>;
      function fixLinks(){
        var links = document.querySelectorAll('a[href*="checkout.php"]');
        for (var i=0;i<links.length;i++){
          var href = links[i].getAttribute('href');
          if(!href) continue;
          // evita duplicar
            var base = 'checkout.php?slug=' + encodeURIComponent(slug);
            if (href.indexOf('checkout.php') !== -1) {
              // remove tudo após '?slug=...'
              if (href.indexOf('?') !== -1) {
                // mantém slug original
                var m = href.match(/slug=([^&]+)/);
                if (m) base = 'checkout.php?slug=' + m[1];
              }
              links[i].setAttribute('href', base + (utmQuery ? '&' + utmQuery : ''));
            }
        }
      }
      fixLinks();
      // Caso elementos dinâmicos apareçam
      var obs = new MutationObserver(fixLinks);
      obs.observe(document.body,{childList:true,subtree:true});
    })();
  </script>
  <footer class="footer">
    <style>
      .footer-main {
        background: #222;
        color: #eee;
        margin-top: 40px;
        padding: 0;
      }
      .footer-content {
        max-width: 1400px;
        margin: 0 auto;
        padding: 38px 0 18px 0;
        display: flex;
        gap: 0;
        flex-wrap: wrap;
        justify-content: space-between;
      }
      .footer-col {
        flex: 1;
        min-width: 220px;
        margin-left: 38px;
      }
      .footer-col h4 {
        color: #00c853;
        margin: 10px 0 8px 0;
      }
      .footer-col ul {
        list-style: none;
        padding: 0;
        margin: 0;
      }
      .footer-col ul li {
        margin-bottom: 6px;
      }
      .footer-col a {
        color: #eee;
        text-decoration: none;
      }
      .footer-col a:hover {
        color: #00c853;
        text-decoration: underline;
      }
      .footer-bottom {
        background: #444;
        color: #eee;
        text-align: center;
        padding: 8px 0;
        font-size: 0.98em;
      }
      @media (max-width: 900px) {
        .footer-content {
          flex-direction: column;
          gap: 18px;
          padding: 18px 8px 18px 8px;
        }
        .footer-col {
          margin-left: 0;
          margin-bottom: 18px;
        }
      }
      .footer-icon {
        vertical-align: middle;
        margin-bottom: 10px;
        height: 32px;
      }
      .footer-app-icon {
        height: 130px;
        margin-right: 8px;
      }
      .footer-selo-icon {
        height: 38px;
        margin-top: 10px;
      }
    </style>
    <div class="footer-main">
      <div class="footer-content">
        <div class="footer-col">
          <img src="logo.png" alt="vakinha" class="footer-icon">
          <h4>Links rápidos</h4>
          <ul>
            <li><a href="#">Quem somos</a></li>
            <li><a href="#">Vaquinhas</a></li>
            <li><a href="#">Criar vaquinha</a></li>
            <li><a href="#">Login</a></li>
            <li><a href="#">Política de privacidade</a></li>
          </ul>
        </div>
        <div class="footer-col">
          <h4>Dúvidas frequentes</h4>
          <ul>
            <li><a href="#">Taxas e prazos</a></li>
            <li><a href="#">Blog do Vakinha</a></li>
            <li><a href="#">Segurança e transparência</a></li>
          </ul>
        </div>
        <div class="footer-col">
          <h4>Fale conosco</h4>
          <ul>
            <li><a href="#">Clique aqui para falar conosco</a></li>
            <li style="color:#eee;">De Segunda à Sexta<br>Das 9:30 às 17:00</li>
                        <li>
              <img src="selo.png" alt="Selo de segurança" class="footer-selo-icon">
            </li>
          </ul>
        </div>
        <div class="footer-col">
          <h4>Baixe nosso App</h4>
          <ul>
            <li>
              <a href="#"><img src="apps.png" alt="Google Play e App Store" class="footer-app-icon"></a>
            </li>
          </ul>
        </div>
      </div>
      <div class="footer-bottom">
        © <?= date('Y') ?> - Todos direitos reservados
      </div>
    </div>
  </footer>
  <script>
    // Função de compartilhamento: usa Web Share API, tenta WhatsApp no mobile e copia link no desktop.
    function sharePage() {
      try {
        var url = window.location.href;
        var title = document.title || 'Confira esta vaquinha';
        var text = title + '\n' + url;

        // Web Share API (mobile/desktop suportados)
        if (navigator.share) {
          navigator.share({ title: title, text: text, url: url }).catch(function(){});
          return;
        }

        // Detecta mobile (simples)
        var isMobile = /Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

        // No mobile, tenta abrir o app do WhatsApp; se falhar, cai para wa.me web
        if (isMobile) {
          var waApp = 'whatsapp://send?text=' + encodeURIComponent(text);
          var waWeb = 'https://wa.me/?text=' + encodeURIComponent(text);
          // tenta abrir o app (se não existir, após timeout abre a versão web)
          window.location.href = waApp;
          setTimeout(function(){ window.location.href = waWeb; }, 800);
          return;
        }

        // Desktop: tenta copiar para clipboard, senão usa prompt
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function(){
            alert('Link copiado para a área de transferência');
          }).catch(function(){
            window.prompt('Copie o link:', url);
          });
        } else {
          window.prompt('Copie o link:', url);
        }
      } catch (e) {
        try { window.prompt('Copie o link:', window.location.href); } catch(_) {}
      }
    }
  </script>
  <!-- === TOAST DE COMPRAS (widget) === -->
  <div class="elementor-widget-container">
    <style>
      :root { --cor-de-destaque: #f00; }
      #views b, #vagas b { color: var(--cor-de-destaque); }
      #vagas{ display: none; }
      #views { max-width: 800px; margin: auto; line-height: 1.4; }
      #toast-compras {
        position: fixed; right: 20px; bottom: 20px; z-index: 1000;
        background-color: #FFF; padding: 10px 20px; display: flex; align-items: center; justify-content: center; gap: 20px;
        border-radius: 5px; box-shadow: 1px 1px 5px #00000036;
        transform: translateY(calc(100% + 40px)); transition: all .3s ease; opacity: 0
      }
      #toast-compras.show { transform: none; opacity: 1 }
      #toast-compras * { font-family: "Roboto", sans-serif }
      #toast-compras svg { width: 40px; height: 40px; fill: #11F849; border: 3px solid #11F849; padding: 5px; border-radius: 100px }
      #toast-compras .textos .linha-1 { display: flex; gap: 20px; align-items: center; margin: 0 0 5px }
      #toast-compras .textos .linha-1 .nome { font-size: 16px; font-weight: 500; line-height: 16px; color: #1b1b1b; margin: 0 }
      #toast-compras .textos .linha-1 .tempo { font-size: 14px; font-weight: 400; line-height: 14px; color: #7a7a7a; margin: 0 }
      #toast-compras .textos .pagamento { font-size: 16px; line-height: 1; color: #7a7a7a; margin: 0 }
    </style>
    <div id="toast-compras" class="">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 447.98 319.97">
        <path d="M438.6 105.4C451.1 117.9 451.1 138.1 438.6 150.6L182.6 406.6C170.1 419.1 149.9 419.1 137.4 406.6L9.372 278.6C-3.124 266.1-3.124 245.9 9.372 233.4C21.87 220.9 42.13 220.9 54.63 233.4L159.1 338.7L393.4 105.4C405.9 92.88 426.1 92.88 438.6 105.4H438.6z"></path>
      </svg>
      <div class="textos">
        <div class="linha-1">
          <p class="nome">Raquel Silveira</p>
          <p class="tempo">há 22 minutos</p>
        </div>
        <p class="pagamento">Doou no pix</p>
      </div>
    </div>
    <script>
      const delayEmSegundosVagas = 1;
      const delayEmSegundosCompras = 1;
      const manterVagasVisiveis = false;
      const compras = [
        { nome: "Vanessa R.", tempo: "45 minutos", pagamento: "pix" },
        { nome: "Raquel Silveira", tempo: "22 minutos", pagamento: "pix" },
        { nome: "Roberta Cavalcante", tempo: "32 minutos", pagamento: "pix" },
        { nome: "Ana R.", tempo: "12 minutos", pagamento: "Pix" },
        { nome: "Marcela F.", tempo: "51 minutos", pagamento: "cartão de débito" },
        { nome: "Juliana D.", tempo: "37 minutos", pagamento: "pix" }
      ];
      setTimeout(() => { configurarVagas(); }, delayEmSegundosVagas * 1000)
      setTimeout(() => { mostrarToast(); }, delayEmSegundosCompras * 1000)
      document.addEventListener('DOMContentLoaded', function(){
        inserirData();
        configurarViews();
        if(manterVagasVisiveis) exibirVagas();
      });
      function inserirLocalizacao() {
        fetch("https://wtfismyip.com/json").then(res => res.json().then((data) => {
          const cidade = data.YourFuckingLocation.split(',')[0];
          document.querySelector('span#address').innerHTML = `de <b>${cidade}</b>`;
        }))
      }
      function exibirVagas(){ document.querySelector('#vagas').style.display = "block"; }
      function configurarVagas() {
        exibirVagas();
        const vagasInterval = setInterval(() => {
          const vagasElement = document.querySelector('#vagas b')
          const vagas = Number(vagasElement.innerText);
          if(vagas >= 100) {
            vagasElement.innerText = vagas - randomNumber(3, 8);
          } else if(vagas >= 30) {
            vagasElement.innerText = vagas - randomNumber(1, 3);
          } else if(vagas >= 4) {
            vagasElement.innerText = vagas - 1;
          } else {
            clearInterval(vagasInterval);
          }
        }, randomNumber(4, 8) * 1000);
      }
      function configurarViews() {
        const viewsInterval = setInterval(() => {
          const viewsElement = document.querySelector('#views b.qtd');
          const views = Number(viewsElement.innerText);
          if(views <= 500) {
            viewsElement.innerText = views + randomNumber(3, 8);
          } else if(views >= 2300) {
            viewsElement.innerText = views + randomNumber(-5, 5);
          } else {
            viewsElement.innerText = views + randomNumber(-3, 12);
          }
        }, 5 * 1000);
      }
      function mostrarToast() {
        configurarToast()
        document.querySelector('#toast-compras').classList.add('show');
        setTimeout(() => {
          document.querySelector('#toast-compras').classList.remove('show');
          setTimeout(() => { mostrarToast(); }, randomNumber(5, 10) * 1000)
        }, 2500);
      }
      function configurarToast() {
        document.querySelector('#toast-compras .nome').innerText = compras[0].nome;
        document.querySelector('#toast-compras .tempo').innerText = `há ${compras[0].tempo}`;
        document.querySelector('#toast-compras .pagamento').innerText = `Doou no ${compras[0].pagamento}`;
        compras.push(compras.shift());
      }
      function randomNumber(min, max) {
        min = Math.ceil(min);
        max = Math.floor(max);
        return Math.floor(Math.random() * (max - min + 1)) + min;
      }
      function inserirData() {
        const data = new Date().toLocaleDateString();
        const el = document.querySelector('#views b.date');
        if (el) el.innerText = data;
      }
    </script>
  </div>
</body>
</html>
