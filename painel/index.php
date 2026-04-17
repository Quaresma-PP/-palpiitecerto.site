<?php
session_start();
require_once __DIR__ . '/config.php';

// --- AUTH ---
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}
if (isset($_POST['reset_metrics']) && !empty($_SESSION['authed'])) {
    $pdo = getDB();
    $pdo->exec("DELETE FROM pageviews");
    header('Location: index.php?reset=ok');
    exit;
}
if (isset($_POST['password'])) {
    if ($_POST['password'] === PANEL_PASSWORD) {
        $_SESSION['authed'] = true;
    } else {
        $loginError = 'Senha incorreta';
    }
}
if (empty($_SESSION['authed'])) {
    showLogin($loginError ?? null);
    exit;
}

// --- FILTERS ---
$range = $_GET['range'] ?? '7d';
$customFrom = $_GET['from'] ?? '';
$customTo = $_GET['to'] ?? '';
$funnel = $_GET['funnel'] ?? '';
$utmSource = $_GET['utm_source'] ?? '';
$utmMedium = $_GET['utm_medium'] ?? '';
$utmCampaign = $_GET['utm_campaign'] ?? '';
$device = $_GET['device'] ?? '';

switch ($range) {
    case 'today':    $dateFrom = date('Y-m-d'); $dateTo = date('Y-m-d'); break;
    case 'yesterday':$dateFrom = date('Y-m-d', strtotime('-1 day')); $dateTo = $dateFrom; break;
    case '7d':       $dateFrom = date('Y-m-d', strtotime('-6 days')); $dateTo = date('Y-m-d'); break;
    case '30d':      $dateFrom = date('Y-m-d', strtotime('-29 days')); $dateTo = date('Y-m-d'); break;
    case 'custom':   $dateFrom = $customFrom ?: date('Y-m-d', strtotime('-6 days')); $dateTo = $customTo ?: date('Y-m-d'); break;
    default:         $dateFrom = date('Y-m-d', strtotime('-6 days')); $dateTo = date('Y-m-d');
}

$pdo = getDB();

// Build WHERE
$where = "WHERE created_at >= :date_from AND created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)";
$params = [':date_from' => $dateFrom, ':date_to' => $dateTo];
if ($funnel !== '' && in_array($funnel, ['1','2'], true)) {
    $where .= " AND funnel_id = :funnel_id";
    $params[':funnel_id'] = (int)$funnel;
}
if ($utmSource) { $where .= " AND utm_source = :utm_source"; $params[':utm_source'] = $utmSource; }
if ($utmMedium) { $where .= " AND utm_medium = :utm_medium"; $params[':utm_medium'] = $utmMedium; }
if ($utmCampaign) { $where .= " AND utm_campaign = :utm_campaign"; $params[':utm_campaign'] = $utmCampaign; }
if ($device) { $where .= " AND device_type = :device"; $params[':device'] = $device; }

// --- QUERIES ---

// Funnel metrics
$sql = "SELECT page_slug, COUNT(*) as views, COUNT(DISTINCT visitor_id) as unique_visitors FROM pageviews $where GROUP BY page_slug";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawMetrics = $stmt->fetchAll();
$metricsMap = [];
foreach ($rawMetrics as $r) { $metricsMap[$r['page_slug']] = $r; }

$funnelPages = FUNNEL_PAGES;
$funnelData = [];
$prevUniques = 0;
$i = 0;
foreach ($funnelPages as $slug => $label) {
    $views = $metricsMap[$slug]['views'] ?? 0;
    $uniques = $metricsMap[$slug]['unique_visitors'] ?? 0;
    $passRate = ($i === 0) ? 100 : ($prevUniques > 0 ? round(($uniques / $prevUniques) * 100, 1) : 0);
    $dropOff = ($i === 0) ? 0 : round(100 - $passRate, 1);
    $funnelData[] = [
        'slug' => $slug,
        'label' => $label,
        'views' => $views,
        'uniques' => $uniques,
        'pass_rate' => $passRate,
        'drop_off' => $dropOff,
    ];
    $prevUniques = $uniques;
    $i++;
}

$firstUniques = $funnelData[0]['uniques'] ?? 0;
$lastUniques = end($funnelData)['uniques'] ?? 0;
$overallConversion = $firstUniques > 0 ? round(($lastUniques / $firstUniques) * 100, 2) : 0;

// Total stats
$sqlTotals = "SELECT COUNT(*) as total_views, COUNT(DISTINCT visitor_id) as total_visitors, COUNT(DISTINCT session_id) as total_sessions FROM pageviews $where";
$stmt = $pdo->prepare($sqlTotals);
$stmt->execute($params);
$totals = $stmt->fetch();

// Daily chart data
$sqlDaily = "SELECT DATE(created_at) as day, page_slug, COUNT(DISTINCT visitor_id) as uniques FROM pageviews $where GROUP BY day, page_slug ORDER BY day";
$stmt = $pdo->prepare($sqlDaily);
$stmt->execute($params);
$dailyRaw = $stmt->fetchAll();
$dailyMap = [];
$allDays = [];
foreach ($dailyRaw as $r) {
    $dailyMap[$r['page_slug']][$r['day']] = (int)$r['uniques'];
    $allDays[$r['day']] = true;
}
ksort($allDays);
$dayLabels = array_keys($allDays);

// Device breakdown
$sqlDevice = "SELECT device_type, COUNT(DISTINCT visitor_id) as cnt FROM pageviews $where GROUP BY device_type ORDER BY cnt DESC";
$stmt = $pdo->prepare($sqlDevice);
$stmt->execute($params);
$deviceData = $stmt->fetchAll();

// Hourly heatmap
$sqlHourly = "SELECT DAYOFWEEK(created_at) as dow, HOUR(created_at) as hr, COUNT(*) as cnt FROM pageviews $where GROUP BY dow, hr";
$stmt = $pdo->prepare($sqlHourly);
$stmt->execute($params);
$hourlyRaw = $stmt->fetchAll();
$heatmap = array_fill(1, 7, array_fill(0, 24, 0));
$heatmapMax = 1;
foreach ($hourlyRaw as $r) {
    $heatmap[(int)$r['dow']][(int)$r['hr']] = (int)$r['cnt'];
    if ((int)$r['cnt'] > $heatmapMax) $heatmapMax = (int)$r['cnt'];
}

// Top UTM sources
$sqlUtm = "SELECT COALESCE(utm_source,'(direto)') as src, COUNT(DISTINCT visitor_id) as visitors,
    COUNT(DISTINCT CASE WHEN page_slug='etapa5-vsl' THEN visitor_id END) as converted
    FROM pageviews $where GROUP BY src ORDER BY visitors DESC LIMIT 10";
$stmt = $pdo->prepare($sqlUtm);
$stmt->execute($params);
$utmData = $stmt->fetchAll();

// Time between steps
$whereA = str_replace(
    ['created_at >', 'created_at <', 'utm_source =', 'utm_medium =', 'utm_campaign =', 'device_type =', 'funnel_id ='],
    ['a.created_at >', 'a.created_at <', 'a.utm_source =', 'a.utm_medium =', 'a.utm_campaign =', 'a.device_type =', 'a.funnel_id ='],
    $where
);
$sqlTimeBetween = "SELECT
    a.page_slug as from_page,
    b.page_slug as to_page,
    ROUND(AVG(TIMESTAMPDIFF(SECOND, a.created_at, b.created_at))) as avg_seconds
    FROM pageviews a
    INNER JOIN pageviews b ON a.session_id = b.session_id
    $whereA
    AND (
        (a.page_slug='etapa1-vsl' AND b.page_slug='etapa2-escolher') OR
        (a.page_slug='etapa2-escolher' AND b.page_slug='etapa3-vsl') OR
        (a.page_slug='etapa3-vsl' AND b.page_slug='etapa4-quiz') OR
        (a.page_slug='etapa4-quiz' AND b.page_slug='etapa5-vsl')
    )
    AND b.created_at > a.created_at
    GROUP BY a.page_slug, b.page_slug";
$stmt = $pdo->prepare($sqlTimeBetween);
$stmt->execute($params);
$timeBetween = $stmt->fetchAll();
$timeMap = [];
foreach ($timeBetween as $t) { $timeMap[$t['from_page'] . '->' . $t['to_page']] = (int)$t['avg_seconds']; }

// UTM filter options
$sqlUtmOpts = "SELECT DISTINCT utm_source FROM pageviews WHERE utm_source IS NOT NULL AND utm_source != '' ORDER BY utm_source";
$utmSourceOpts = $pdo->query($sqlUtmOpts)->fetchAll(PDO::FETCH_COLUMN);
$sqlUtmMedOpts = "SELECT DISTINCT utm_medium FROM pageviews WHERE utm_medium IS NOT NULL AND utm_medium != '' ORDER BY utm_medium";
$utmMediumOpts = $pdo->query($sqlUtmMedOpts)->fetchAll(PDO::FETCH_COLUMN);
$sqlUtmCampOpts = "SELECT DISTINCT utm_campaign FROM pageviews WHERE utm_campaign IS NOT NULL AND utm_campaign != '' ORDER BY utm_campaign";
$utmCampaignOpts = $pdo->query($sqlUtmCampOpts)->fetchAll(PDO::FETCH_COLUMN);

// UTM Content (criativos) - substitui Novos vs Recorrentes
$sqlUtmContent = "SELECT COALESCE(utm_content,'(sem criativo)') as raw_content, COUNT(DISTINCT visitor_id) as visitors,
    COUNT(DISTINCT CASE WHEN page_slug='etapa5-vsl' THEN visitor_id END) as converted
    FROM pageviews $where AND utm_content IS NOT NULL AND utm_content != '' GROUP BY raw_content ORDER BY visitors DESC LIMIT 15";
try {
    $stmtUtmContent = $pdo->prepare($sqlUtmContent);
    $stmtUtmContent->execute($params);
    $utmContentRaw = $stmtUtmContent->fetchAll();
} catch (Exception $e) {
    $utmContentRaw = [];
}
$utmContentData = [];
$creativeCounts = [];
foreach ($utmContentRaw as $r) {
    $name = extractCreativeName($r['raw_content']);
    if (!isset($creativeCounts[$name])) {
        $creativeCounts[$name] = ['visitors' => 0, 'converted' => 0];
    }
    $creativeCounts[$name]['visitors'] += (int)$r['visitors'];
    $creativeCounts[$name]['converted'] += (int)$r['converted'];
}
uasort($creativeCounts, function($a, $b) { return $b['visitors'] - $a['visitors']; });
$utmContentData = array_slice($creativeCounts, 0, 15, true);

// Simplifica fontes de tráfego (agrupa fbjLj... -> fb)
$utmDataSimplified = [];
foreach ($utmData as $u) {
    $simpleSrc = simplifySource($u['src']);
    if (!isset($utmDataSimplified[$simpleSrc])) {
        $utmDataSimplified[$simpleSrc] = ['visitors' => 0, 'converted' => 0];
    }
    $utmDataSimplified[$simpleSrc]['visitors'] += (int)$u['visitors'];
    $utmDataSimplified[$simpleSrc]['converted'] += (int)$u['converted'];
}
uasort($utmDataSimplified, function($a, $b) { return $b['visitors'] - $a['visitors']; });
$utmData = [];
foreach ($utmDataSimplified as $src => $v) {
    $utmData[] = ['src' => $src, 'visitors' => $v['visitors'], 'converted' => $v['converted']];
}
$utmData = array_slice($utmData, 0, 10);

function extractCreativeName($content) {
    if (empty($content) || $content === '(sem criativo)') return '(sem criativo)';
    $part = strpos($content, '::') !== false ? strstr($content, '::', true) : $content;
    $part = trim($part);
    $part = preg_replace('/\|[0-9]{8,}.*$/u', '', $part);
    $part = trim($part, " \t\n\r\0\x0B|");
    return $part ?: substr($content, 0, 50);
}

function simplifySource($src) {
    if (empty($src) || $src === '(direto)') return $src;
    $lower = strtolower($src);
    if (strpos($lower, 'fb') === 0 || preg_match('/^fb[a-z0-9]*$/i', $src)) return 'fb';
    if (strpos($lower, 'google') === 0 || strpos($lower, 'gcl') === 0) return 'google';
    if (strpos($lower, 'ig') === 0 || strpos($lower, 'instagram') === 0) return 'ig';
    if (strpos($lower, 'tt') === 0 || strpos($lower, 'tiktok') === 0) return 'tiktok';
    if (strpos($lower, 'email') === 0 || strpos($lower, 'mail') === 0) return 'email';
    return $src;
}

function formatSeconds($s) {
    if ($s === null || $s === 0) return '—';
    if ($s < 60) return $s . 's';
    $m = floor($s / 60);
    $sec = $s % 60;
    if ($m < 60) return $m . 'min ' . $sec . 's';
    $h = floor($m / 60);
    $rm = $m % 60;
    return $h . 'h ' . $rm . 'min';
}

function showLogin($error = null) {
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel Analytics — Login</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{dark:'#0f172a','dark-card':'#1e293b','dark-border':'#334155','accent':'#3b82f6','accent-hover':'#2563eb'}}}}</script>
</head>
<body class="bg-dark min-h-screen flex items-center justify-center">
<div class="bg-dark-card border border-dark-border rounded-2xl p-8 w-full max-w-sm shadow-2xl">
  <h1 class="text-2xl font-bold text-white text-center mb-2">Painel Analytics</h1>
  <p class="text-slate-400 text-center text-sm mb-6">Digite a senha para acessar</p>
  <?php if ($error): ?><p class="text-red-400 text-sm text-center mb-4"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <form method="POST">
    <input type="password" name="password" placeholder="Senha" required
      class="w-full px-4 py-3 bg-dark border border-dark-border rounded-lg text-white placeholder-slate-500 focus:outline-none focus:border-accent mb-4">
    <button type="submit" class="w-full py-3 bg-accent hover:bg-accent-hover text-white font-semibold rounded-lg transition-colors">Entrar</button>
  </form>
</div>
</body>
</html>
<?php
}

// --- RENDER DASHBOARD ---
$dowNames = [1=>'Dom',2=>'Seg',3=>'Ter',4=>'Qua',5=>'Qui',6=>'Sex',7=>'Sáb'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel Analytics — Funil</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{dark:'#0f172a','dark-card':'#1e293b','dark-card2':'#253349','dark-border':'#334155','accent':'#3b82f6','accent-hover':'#2563eb','green':'#22c55e','red':'#ef4444','yellow':'#eab308','purple':'#a855f7'}}}}</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<style>
  body{font-family:'Inter',system-ui,-apple-system,sans-serif}
  .stat-card{transition:transform .15s,box-shadow .15s}
  .stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,.3)}
  .funnel-bar{transition:width .8s cubic-bezier(.4,0,.2,1)}
  select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .75rem center;-webkit-appearance:none;appearance:none;padding-right:2.5rem}
  .heatmap-cell{transition:opacity .2s}
  @media(max-width:640px){.responsive-grid{grid-template-columns:1fr!important}}
</style>
</head>
<body class="bg-dark text-slate-200 min-h-screen">

<!-- HEADER -->
<header class="bg-dark-card border-b border-dark-border sticky top-0 z-50">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-8 h-8 bg-accent rounded-lg flex items-center justify-center">
        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
      </div>
      <h1 class="text-lg font-bold text-white">Painel Analytics</h1>
    </div>
    <div class="flex items-center gap-3">
      <button onclick="location.reload()" id="refreshBtn" class="px-3 py-1.5 bg-accent/10 hover:bg-accent/20 border border-accent/30 text-accent text-sm font-medium rounded-lg transition-colors flex items-center gap-1.5">
        <svg id="refreshIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Atualizar
      </button>
      <form method="POST" class="inline">
        <button name="logout" value="1" class="text-slate-400 hover:text-white text-sm transition-colors flex items-center gap-1">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          Sair
        </button>
      </form>
    </div>
  </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-6 space-y-6">

<!-- RESET SUCCESS -->
<?php if (isset($_GET['reset']) && $_GET['reset'] === 'ok'): ?>
<div class="bg-green/10 border border-green/30 rounded-xl px-4 py-3 flex items-center gap-3">
  <svg class="w-5 h-5 text-green flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
  <span class="text-green text-sm font-medium">Métricas resetadas com sucesso!</span>
</div>
<?php endif; ?>

<!-- FILTERS -->
<div class="bg-dark-card border border-dark-border rounded-xl overflow-hidden">
  <div class="px-5 py-3 border-b border-dark-border flex items-center justify-between">
    <div class="flex items-center gap-2">
      <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
      <h3 class="text-sm font-semibold text-white">Filtros</h3>
    </div>
    <button type="button" onclick="document.getElementById('filterBody').classList.toggle('hidden');this.querySelector('svg').classList.toggle('rotate-180')" class="text-slate-400 hover:text-white transition-colors p-1">
      <svg class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
  </div>
  <form method="GET" id="filterBody" class="p-5">
    <!-- Periodo row -->
    <div class="mb-4">
      <label class="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">Período</label>
      <div class="flex flex-wrap gap-2">
        <?php
        $ranges = ['today'=>'Hoje','yesterday'=>'Ontem','7d'=>'7 dias','30d'=>'30 dias','custom'=>'Personalizado'];
        foreach ($ranges as $val => $lbl):
          $active = $range === $val;
        ?>
        <label class="cursor-pointer">
          <input type="radio" name="range" value="<?= $val ?>" <?= $active?'checked':'' ?> onchange="toggleCustom(this.value)" class="sr-only peer">
          <span class="inline-block px-4 py-2 text-xs font-medium rounded-lg border transition-all
            peer-checked:bg-accent peer-checked:border-accent peer-checked:text-white
            bg-dark border-dark-border text-slate-400 hover:text-white hover:border-slate-500"><?= $lbl ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div id="customDates" class="flex gap-3 mt-3 <?= $range==='custom'?'':'hidden' ?>">
        <div>
          <label class="block text-xs text-slate-500 mb-1">De</label>
          <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="bg-dark border border-dark-border text-white text-sm rounded-lg px-3 py-2 focus:border-accent focus:outline-none transition-colors">
        </div>
        <div>
          <label class="block text-xs text-slate-500 mb-1">Até</label>
          <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="bg-dark border border-dark-border text-white text-sm rounded-lg px-3 py-2 focus:border-accent focus:outline-none transition-colors">
        </div>
      </div>
    </div>
    <!-- Funil + UTM + Device row -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-5">
      <div>
        <label class="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">Funil</label>
        <select name="funnel" class="w-full bg-dark border border-dark-border text-white text-sm rounded-lg px-3 py-2 focus:border-accent focus:outline-none transition-colors">
          <option value="" <?= $funnel===''?'selected':'' ?>>Todos</option>
          <option value="1" <?= $funnel==='1'?'selected':'' ?>>Funil 1 (jornal-nacional)</option>
          <option value="2" <?= $funnel==='2'?'selected':'' ?>>Funil 2 (jornal-nacional-funil2)</option>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">UTM Source</label>
        <select name="utm_source" class="w-full bg-dark border border-dark-border text-white text-sm rounded-lg px-3 py-2 focus:border-accent focus:outline-none transition-colors">
          <option value="">Todas</option>
          <?php foreach ($utmSourceOpts as $o): ?><option value="<?= htmlspecialchars($o) ?>" <?= $utmSource===$o?'selected':'' ?>><?= htmlspecialchars($o) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">UTM Medium</label>
        <select name="utm_medium" class="w-full bg-dark border border-dark-border text-white text-sm rounded-lg px-3 py-2 focus:border-accent focus:outline-none transition-colors">
          <option value="">Todos</option>
          <?php foreach ($utmMediumOpts as $o): ?><option value="<?= htmlspecialchars($o) ?>" <?= $utmMedium===$o?'selected':'' ?>><?= htmlspecialchars($o) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">UTM Campaign</label>
        <select name="utm_campaign" class="w-full bg-dark border border-dark-border text-white text-sm rounded-lg px-3 py-2 focus:border-accent focus:outline-none transition-colors">
          <option value="">Todas</option>
          <?php foreach ($utmCampaignOpts as $o): ?><option value="<?= htmlspecialchars($o) ?>" <?= $utmCampaign===$o?'selected':'' ?>><?= htmlspecialchars($o) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-400 uppercase tracking-wider font-semibold mb-2">Dispositivo</label>
        <select name="device" class="w-full bg-dark border border-dark-border text-white text-sm rounded-lg px-3 py-2 focus:border-accent focus:outline-none transition-colors">
          <option value="">Todos</option>
          <option value="mobile" <?= $device==='mobile'?'selected':'' ?>>Mobile</option>
          <option value="desktop" <?= $device==='desktop'?'selected':'' ?>>Desktop</option>
          <option value="tablet" <?= $device==='tablet'?'selected':'' ?>>Tablet</option>
        </select>
      </div>
    </div>
    <!-- Actions -->
    <div class="flex items-center justify-between pt-4 border-t border-dark-border">
      <div class="flex gap-2">
        <button type="submit" class="px-6 py-2.5 bg-accent hover:bg-accent-hover text-white text-sm font-semibold rounded-lg transition-colors flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          Aplicar Filtros
        </button>
        <a href="index.php" class="px-5 py-2.5 bg-dark border border-dark-border text-slate-400 hover:text-white text-sm rounded-lg transition-colors inline-flex items-center gap-2">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          Limpar
        </a>
      </div>
      <button type="button" onclick="document.getElementById('resetModal').classList.remove('hidden')" class="px-4 py-2.5 text-red/70 hover:text-red hover:bg-red/10 text-xs font-medium rounded-lg transition-colors flex items-center gap-1.5 border border-transparent hover:border-red/20">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
        Resetar Métricas
      </button>
    </div>
  </form>
</div>

<!-- RESET MODAL -->
<div id="resetModal" class="hidden fixed inset-0 z-[999] flex items-center justify-center" style="background:rgba(0,0,0,.6);backdrop-filter:blur(4px)">
  <div class="bg-dark-card border border-dark-border rounded-2xl p-6 w-full max-w-sm shadow-2xl mx-4">
    <div class="flex items-center justify-center w-12 h-12 bg-red/10 rounded-full mx-auto mb-4">
      <svg class="w-6 h-6 text-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
    </div>
    <h3 class="text-lg font-bold text-white text-center mb-2">Resetar todas as métricas?</h3>
    <p class="text-sm text-slate-400 text-center mb-6">Essa ação vai apagar permanentemente todos os dados de visitas e não pode ser desfeita.</p>
    <div class="flex gap-3">
      <button onclick="document.getElementById('resetModal').classList.add('hidden')" class="flex-1 px-4 py-2.5 bg-dark border border-dark-border text-slate-300 hover:text-white text-sm font-medium rounded-lg transition-colors">Cancelar</button>
      <form method="POST" class="flex-1">
        <button name="reset_metrics" value="1" class="w-full px-4 py-2.5 bg-red hover:bg-red/80 text-white text-sm font-semibold rounded-lg transition-colors">Sim, Resetar</button>
      </form>
    </div>
  </div>
</div>

<!-- OVERVIEW CARDS -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
  <div class="stat-card bg-dark-card border border-dark-border rounded-xl p-4">
    <p class="text-xs text-slate-400 uppercase tracking-wider">Total Pageviews</p>
    <p class="text-2xl font-bold text-white mt-1"><?= number_format($totals['total_views']) ?></p>
  </div>
  <div class="stat-card bg-dark-card border border-dark-border rounded-xl p-4">
    <p class="text-xs text-slate-400 uppercase tracking-wider">Visitantes Únicos</p>
    <p class="text-2xl font-bold text-white mt-1"><?= number_format($totals['total_visitors']) ?></p>
  </div>
  <div class="stat-card bg-dark-card border border-dark-border rounded-xl p-4">
    <p class="text-xs text-slate-400 uppercase tracking-wider">Sessões</p>
    <p class="text-2xl font-bold text-white mt-1"><?= number_format($totals['total_sessions']) ?></p>
  </div>
  <div class="stat-card bg-dark-card border border-dark-border rounded-xl p-4">
    <p class="text-xs text-slate-400 uppercase tracking-wider">Conversão Geral</p>
    <p class="text-2xl font-bold <?= $overallConversion >= 5 ? 'text-green' : ($overallConversion >= 2 ? 'text-yellow' : 'text-red') ?> mt-1"><?= $overallConversion ?>%</p>
    <p class="text-xs text-slate-500 mt-1">Etapa 1 → Oferta</p>
  </div>
</div>

<!-- FUNNEL VISUALIZATION -->
<div class="bg-dark-card border border-dark-border rounded-xl p-6">
  <h2 class="text-lg font-bold text-white mb-6">Funil de Conversão</h2>
  <?php
    $maxUniques = 1;
    foreach ($funnelData as $s) { if ($s['uniques'] > $maxUniques) $maxUniques = $s['uniques']; }
    $colors = ['#3b82f6','#6366f1','#8b5cf6','#a855f7','#d946ef','#f43f5e'];
    $totalSteps = count($funnelData);
    $maxBarH = 180;
  ?>
  <div class="overflow-x-auto pb-2">
    <div style="display:flex;align-items:flex-end;gap:6px;min-width:<?= max($totalSteps * 120, 650) ?>px">
      <?php foreach ($funnelData as $idx => $step):
        $color = $colors[$idx] ?? '#3b82f6';
        $barH = max(round(($step['uniques'] / $maxUniques) * $maxBarH), 32);
        $shortLabel = preg_replace('/^Etapa \d+ — /', '', $step['label']);
      ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center">
        <!-- Bar -->
        <div style="width:100%;max-width:110px;height:<?= $barH ?>px;background:<?= $color ?>;border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:center;position:relative;transition:height .6s ease">
          <span style="font-size:18px;font-weight:800;color:#fff"><?= number_format($step['uniques']) ?></span>
        </div>
        <!-- Info below -->
        <div style="width:100%;max-width:130px;background:#1a2436;border-radius:0 0 8px 8px;padding:10px 6px;text-align:center;border-top:2px solid <?= $color ?>">
          <p style="font-size:11px;font-weight:700;color:#fff;margin-bottom:4px;line-height:1.3"><?= htmlspecialchars($shortLabel) ?></p>
          <p style="font-size:10px;color:#94a3b8;margin-bottom:4px"><?= number_format($step['views']) ?> views &middot; <?= number_format($step['uniques']) ?> únicos</p>
          <?php if ($idx > 0): ?>
            <p style="font-size:12px;font-weight:700;margin-bottom:2px" class="<?= $step['pass_rate'] >= 50 ? 'text-green' : ($step['pass_rate'] >= 25 ? 'text-yellow' : 'text-red') ?>"><?= $step['pass_rate'] ?>%</p>
            <p style="font-size:9px;color:#64748b">-<?= $step['drop_off'] ?>% drop</p>
          <?php else: ?>
            <p style="font-size:12px;font-weight:700;color:#3b82f6">ENTRADA</p>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($idx < $totalSteps - 1): ?>
      <div style="flex:0 0 16px;display:flex;align-items:center;justify-content:center;align-self:center;margin-bottom:40px">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 10h12m0 0l-4-4m4 4l-4 4" stroke="#475569" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="margin-top:20px;padding-top:16px;border-top:1px solid #334155;display:flex;align-items:center;justify-content:center;gap:12px">
    <span style="font-size:12px;color:#94a3b8">Conversão geral do funil:</span>
    <span style="font-size:20px;font-weight:800" class="<?= $overallConversion >= 5 ? 'text-green' : ($overallConversion >= 2 ? 'text-yellow' : 'text-red') ?>"><?= $overallConversion ?>%</span>
    <span style="font-size:11px;color:#64748b">(<?= number_format($funnelData[0]['uniques'] ?? 0) ?> → <?= number_format(end($funnelData)['uniques'] ?? 0) ?>)</span>
  </div>
</div>

<!-- TIME BETWEEN STEPS -->
<div class="bg-dark-card border border-dark-border rounded-xl p-6">
  <h2 class="text-lg font-bold text-white mb-4">Tempo Médio Entre Etapas</h2>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
    <?php
    $stepPairs = [
      ['etapa1-vsl','etapa2-escolher','Etapa 1 → 2'],
      ['etapa2-escolher','etapa3-vsl','Etapa 2 → 3'],
      ['etapa3-vsl','etapa4-quiz','Etapa 3 → 4'],
      ['etapa4-quiz','etapa5-vsl','Etapa 4 → 5'],
    ];
    foreach ($stepPairs as $pair):
      $key = $pair[0] . '->' . $pair[1];
      $secs = $timeMap[$key] ?? null;
    ?>
    <div class="bg-dark-card2 border border-dark-border rounded-lg p-3 text-center">
      <p class="text-xs text-slate-400 mb-1"><?= $pair[2] ?></p>
      <p class="text-lg font-bold text-white"><?= formatSeconds($secs) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- CHARTS ROW -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

  <!-- Daily chart -->
  <div class="bg-dark-card border border-dark-border rounded-xl p-6">
    <h2 class="text-lg font-bold text-white mb-4">Visitantes Únicos por Dia</h2>
    <div style="position:relative;height:280px">
      <canvas id="dailyChart"></canvas>
    </div>
  </div>

  <!-- Device breakdown -->
  <div class="bg-dark-card border border-dark-border rounded-xl p-6">
    <h2 class="text-lg font-bold text-white mb-4">Dispositivos</h2>
    <div class="flex items-center justify-center gap-8">
      <div style="position:relative;width:180px;height:180px;flex-shrink:0">
        <canvas id="deviceChart"></canvas>
      </div>
      <div class="space-y-2">
        <?php
        $deviceColors = ['desktop'=>'#3b82f6','mobile'=>'#22c55e','tablet'=>'#eab308'];
        $deviceIcons = ['desktop'=>'💻','mobile'=>'📱','tablet'=>'📋'];
        $totalDevices = array_sum(array_column($deviceData,'cnt')) ?: 1;
        foreach ($deviceData as $d):
          $pct = round(($d['cnt'] / $totalDevices) * 100, 1);
        ?>
        <div class="flex items-center gap-2">
          <span class="w-3 h-3 rounded-full" style="background:<?= $deviceColors[$d['device_type']] ?? '#94a3b8' ?>"></span>
          <span class="text-sm text-slate-300"><?= ucfirst($d['device_type']) ?></span>
          <span class="text-sm font-bold text-white"><?= $pct ?>%</span>
          <span class="text-xs text-slate-500">(<?= number_format($d['cnt']) ?>)</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>

<!-- UTM CONTENT (Criativos) + TOP FONTES -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

  <!-- UTM Content (Criativos) -->
  <div class="bg-dark-card border border-dark-border rounded-xl p-6">
    <h2 class="text-lg font-bold text-white mb-4">Criativos (UTM Content)</h2>
    <?php if (empty($utmContentData)): ?>
      <p class="text-slate-500 text-sm">Nenhum dado com UTM Content encontrado.</p>
    <?php else: ?>
    <div class="overflow-x-auto max-h-80 overflow-y-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-slate-400 uppercase sticky top-0 bg-dark-card">
          <th class="pb-2">Criativo</th><th class="pb-2 text-right">Visitantes</th><th class="pb-2 text-right">Oferta</th><th class="pb-2 text-right">Conv. %</th>
        </tr></thead>
        <tbody>
        <?php foreach ($utmContentData as $name => $v):
          $convRate = $v['visitors'] > 0 ? round(($v['converted'] / $v['visitors']) * 100, 1) : 0;
        ?>
        <tr class="border-t border-dark-border">
          <td class="py-2 text-white font-medium"><?= htmlspecialchars($name) ?></td>
          <td class="py-2 text-right"><?= number_format($v['visitors']) ?></td>
          <td class="py-2 text-right"><?= number_format($v['converted']) ?></td>
          <td class="py-2 text-right font-semibold <?= $convRate >= 5 ? 'text-green' : ($convRate >= 2 ? 'text-yellow' : 'text-red') ?>"><?= $convRate ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Top Fontes de Tráfego -->
  <div class="bg-dark-card border border-dark-border rounded-xl p-6">
    <h2 class="text-lg font-bold text-white mb-4">Top Fontes de Tráfego</h2>
    <?php if (empty($utmData)): ?>
      <p class="text-slate-500 text-sm">Nenhum dado com UTM encontrado.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-slate-400 uppercase">
          <th class="pb-2">Source</th><th class="pb-2 text-right">Visitantes</th><th class="pb-2 text-right">Chegaram na Oferta</th><th class="pb-2 text-right">Conv. %</th>
        </tr></thead>
        <tbody>
        <?php foreach ($utmData as $u):
          $convRate = $u['visitors'] > 0 ? round(($u['converted'] / $u['visitors']) * 100, 1) : 0;
        ?>
        <tr class="border-t border-dark-border">
          <td class="py-2 text-white font-medium"><?= htmlspecialchars($u['src']) ?></td>
          <td class="py-2 text-right"><?= number_format($u['visitors']) ?></td>
          <td class="py-2 text-right"><?= number_format($u['converted']) ?></td>
          <td class="py-2 text-right font-semibold <?= $convRate >= 5 ? 'text-green' : ($convRate >= 2 ? 'text-yellow' : 'text-red') ?>"><?= $convRate ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- HEATMAP -->
<div class="bg-dark-card border border-dark-border rounded-xl p-6">
  <h2 class="text-lg font-bold text-white mb-4">Horários de Pico (Visitas por Hora / Dia da Semana)</h2>
  <div class="overflow-x-auto">
    <table class="text-xs w-full">
      <thead><tr>
        <th class="text-slate-400 text-left py-1 px-1 w-12"></th>
        <?php for ($h=0; $h<24; $h++): ?><th class="text-slate-400 text-center py-1 px-0.5 min-w-[28px]"><?= str_pad($h,2,'0',STR_PAD_LEFT) ?></th><?php endfor; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($dowNames as $dow => $name): ?>
      <tr>
        <td class="text-slate-400 font-medium py-0.5 px-1"><?= $name ?></td>
        <?php for ($h=0; $h<24; $h++):
          $val = $heatmap[$dow][$h] ?? 0;
          $opacity = $heatmapMax > 0 ? max(0.08, $val / $heatmapMax) : 0.08;
        ?>
        <td class="py-0.5 px-0.5">
          <div class="heatmap-cell w-full h-6 rounded-sm flex items-center justify-center text-[10px] font-medium cursor-default"
            style="background:rgba(59,130,246,<?= $opacity ?>);color:<?= $opacity > 0.4 ? '#fff' : 'rgba(148,163,184,0.6)' ?>"
            title="<?= $name ?> <?= str_pad($h,2,'0',STR_PAD_LEFT) ?>h: <?= $val ?> visitas"><?= $val ?: '' ?></div>
        </td>
        <?php endfor; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- DETAILED TABLE -->
<div class="bg-dark-card border border-dark-border rounded-xl p-6">
  <h2 class="text-lg font-bold text-white mb-4">Tabela Detalhada por Etapa</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-xs text-slate-400 uppercase tracking-wider">
        <th class="pb-3">#</th>
        <th class="pb-3">Etapa</th>
        <th class="pb-3 text-right">Pageviews</th>
        <th class="pb-3 text-right">Únicos</th>
        <th class="pb-3 text-right">Taxa Passagem</th>
        <th class="pb-3 text-right">Drop-off</th>
        <th class="pb-3 text-right">Conv. vs Entrada</th>
      </tr></thead>
      <tbody>
      <?php foreach ($funnelData as $idx => $step):
        $vsEntry = $firstUniques > 0 ? round(($step['uniques'] / $firstUniques) * 100, 1) : 0;
      ?>
      <tr class="border-t border-dark-border hover:bg-dark-card2 transition-colors">
        <td class="py-3 text-slate-500"><?= $idx + 1 ?></td>
        <td class="py-3 text-white font-medium"><?= htmlspecialchars($step['label']) ?></td>
        <td class="py-3 text-right"><?= number_format($step['views']) ?></td>
        <td class="py-3 text-right font-semibold text-white"><?= number_format($step['uniques']) ?></td>
        <td class="py-3 text-right font-semibold <?= $step['pass_rate'] >= 50 ? 'text-green' : ($step['pass_rate'] >= 25 ? 'text-yellow' : 'text-red') ?>">
          <?= $idx === 0 ? '—' : $step['pass_rate'] . '%' ?>
        </td>
        <td class="py-3 text-right <?= $step['drop_off'] > 50 ? 'text-red' : 'text-slate-400' ?>">
          <?= $idx === 0 ? '—' : '-' . $step['drop_off'] . '%' ?>
        </td>
        <td class="py-3 text-right text-slate-300"><?= $vsEntry ?>%</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</main>

<footer class="text-center text-xs text-slate-600 py-6">
  Painel Analytics — <?= date('Y') ?>
</footer>

<script>
function toggleCustom(val){
  var show=val==='custom';
  document.getElementById('customDates').classList.toggle('hidden',!show);
}

Chart.defaults.color='#94a3b8';
Chart.defaults.borderColor='#334155';

// Daily chart
var dailyCtx=document.getElementById('dailyChart').getContext('2d');
var dayLabels=<?= json_encode($dayLabels) ?>;
var pageColors={'etapa1-vsl':'#3b82f6','etapa2-escolher':'#6366f1','etapa3-vsl':'#8b5cf6','etapa4-quiz':'#a855f7','etapa5-vsl':'#d946ef'};
var pageLabels=<?= json_encode(FUNNEL_PAGES) ?>;
var dailyDatasets=[];
<?php foreach (FUNNEL_PAGES as $slug => $label): ?>
dailyDatasets.push({
  label:<?= json_encode($label) ?>,
  data:dayLabels.map(function(d){return <?= json_encode($dailyMap[$slug] ?? []) ?>[d]||0}),
  borderColor:pageColors[<?= json_encode($slug) ?>],
  backgroundColor:pageColors[<?= json_encode($slug) ?>]+'20',
  tension:.3,
  borderWidth:2,
  pointRadius:3,
  fill:false
});
<?php endforeach; ?>
new Chart(dailyCtx,{type:'line',data:{labels:dayLabels,datasets:dailyDatasets},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'bottom',labels:{boxWidth:12,padding:12,font:{size:11}}}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});

// Device chart
var deviceCtx=document.getElementById('deviceChart').getContext('2d');
var devLabels=<?= json_encode(array_column($deviceData,'device_type')) ?>;
var devValues=<?= json_encode(array_map('intval',array_column($deviceData,'cnt'))) ?>;
var devColors=devLabels.map(function(d){return {'desktop':'#3b82f6','mobile':'#22c55e','tablet':'#eab308'}[d]||'#94a3b8'});
new Chart(deviceCtx,{type:'doughnut',data:{labels:devLabels.map(function(d){return d.charAt(0).toUpperCase()+d.slice(1)}),datasets:[{data:devValues,backgroundColor:devColors,borderWidth:0}]},options:{responsive:true,maintainAspectRatio:true,plugins:{legend:{display:false}},cutout:'65%'}});

</script>

</body>
</html>
