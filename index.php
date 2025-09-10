<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('America/Chicago');

// Config
$UISP_URL   = getenv("UISP_URL") ?: "https://changeme.unmsapp.com";
$UISP_TOKEN = getenv("UISP_TOKEN") ?: "changeme";

// Embedded Gotify (notifications)
$GOTIFY_URL   = getenv('GOTIFY_URL') ?: 'http://127.0.0.1:18080';
$GOTIFY_TOKEN = getenv('GOTIFY_TOKEN');
if(!$GOTIFY_TOKEN){
    $tokFile = __DIR__ . '/cache/gotify_app_token.txt';
    if(is_file($tokFile)){
        $t = trim(@file_get_contents($tokFile));
        if($t !== '') $GOTIFY_TOKEN = $t;
    }
}

$CACHE_DIR  = __DIR__ . "/cache";
$CACHE_FILE = $CACHE_DIR . "/status_cache.json";
$DB_FILE    = $CACHE_DIR . "/metrics.sqlite";

$FIRST_OFFLINE_THRESHOLD = 30;

// Ensure cache dir
if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0775, true);

// Load cache
$cache = file_exists($CACHE_FILE) ? json_decode(file_get_contents($CACHE_FILE), true) : [];
if (!is_array($cache)) $cache = [];

// SQLite init
$db = new SQLite3($DB_FILE);
$db->exec('PRAGMA journal_mode = wal;');
$db->busyTimeout(5000);
$db->exec("CREATE TABLE IF NOT EXISTS metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id TEXT,
    name TEXT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    cpu INTEGER,
    ram INTEGER,
    temp INTEGER,
    latency REAL,
    online INTEGER
)");

// Asset/version cache-busting
// Calculate a version based on the latest mtime of key files
$ASSET_VERSION = max(
    @filemtime(__FILE__) ?: 0,
    @filemtime(__DIR__ . '/assets/style.css') ?: 0,
    @filemtime(__DIR__ . '/assets/app.js') ?: 0,
    @filemtime(__DIR__ . '/buz.mp3') ?: 0
);

// Helpers
function device_key($dev){ $id=$dev['identification']??[]; return $id['mac'] ?? $id['id'] ?? $id['name'] ?? 'unknown'; }
function is_gateway($d){ return strtolower($d['identification']['role']??'')==="gateway"; }
function is_online($d){ $s=strtolower($d['overview']['status']??''); return in_array($s,['ok','online','active','connected','reachable','enabled']); }
function ping_host($ip){ if(!$ip) return null; $ip=preg_replace('/\/\d+$/','',$ip); $out=@shell_exec("ping -c 1 -W 1 ".escapeshellarg($ip)." 2>&1"); if(preg_match('/time=([\d\.]+)\s*ms/',$out,$m)) return floatval($m[1]); return null; }

function send_gotify($title,$message,$priority=5){
    global $GOTIFY_URL,$GOTIFY_TOKEN;
    if(!$GOTIFY_TOKEN){
        @file_put_contents(__DIR__.'/cache/gotify_log.txt', date('c')." missing GOTIFY_TOKEN\n", FILE_APPEND);
        return false;
    }
    $url = rtrim($GOTIFY_URL,'/').'/message';
    $payload = json_encode(['title'=>$title,'message'=>$message,'priority'=>$priority]);
    $ch=curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>$url,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'X-Gotify-Key: '.$GOTIFY_TOKEN
        ],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>5
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if(!($code>=200 && $code<300)){
        @file_put_contents(__DIR__.'/cache/gotify_log.txt', date('c')." code=$code err=".($err?:'-')." resp=".$resp."\n", FILE_APPEND);
    }
    return $code>=200 && $code<300;
}

// AJAX
if(isset($_GET['ajax'])){
    header("Content-Type: application/json");
    // Prevent caching of AJAX responses
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if($_GET['ajax']==='devices'){
        $ch=curl_init();
        $start=microtime(true);
        curl_setopt_array($ch,[
            CURLOPT_URL=>rtrim($UISP_URL,"/")."/nms/api/v2.1/devices",
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>["accept: application/json","x-auth-token: $UISP_TOKEN"],
            CURLOPT_TIMEOUT=>10
        ]);
        $resp=curl_exec($ch);
        $api_latency=round((microtime(true)-$start)*1000);
        $http_code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        $devices=json_decode($resp,true)?:[];

        $now=time();
        $prev_cache = $cache; // snapshot to detect state transitions
        $out=[];
        $cache_changed=false;
        // Prepare CPE ping batch: up to 10 random CPEs every 3 minutes, avoiding any pinged within the last hour
        $batch_int = intdiv($now, 180); // 3-minute windows
        $meta = $cache['_cpe_batch'] ?? [];
        $selected_cpe_ids = $meta['ids'] ?? [];
        if (($meta['last_batch_int'] ?? null) !== $batch_int) {
            $candidates = [];
            foreach ($devices as $dx) {
                if (!is_gateway($dx)) {
                    $cid = device_key($dx);
                    $lip = $dx['ipAddress'] ?? null;
                    if ($lip) {
                        $lp = $cache[$cid]['last_cpe_ping_at'] ?? 0;
                        if (($now - $lp) >= 3600) { // not pinged in last hour
                            $candidates[] = $cid;
                        }
                    }
                }
            }
            if (!empty($candidates)) {
                shuffle($candidates);
                $selected_cpe_ids = array_slice($candidates, 0, 10);
            } else {
                $selected_cpe_ids = [];
            }
            $cache['_cpe_batch'] = ['last_batch_int'=>$batch_int, 'ids'=>$selected_cpe_ids];
            $cache_changed = true;
        }
        $cpe_ping_set = array_flip($selected_cpe_ids);
        foreach($devices as $d){
            $id=device_key($d);
            $name=$d['identification']['name']??$id;
            $isGw=is_gateway($d);
            $on=is_online($d);
            $cpu=$d['overview']['cpu']??null;
            $ram=$d['overview']['ram']??null;
            $temp=$d['overview']['temperature']??null;
            // Uptime in seconds if available (UISP may expose different keys)
            $uptime=$d['overview']['uptime']
                ?? $d['overview']['uptimeSeconds']
                ?? $d['overview']['uptime_sec']
                ?? null;
            $lat=null;
            $cpe_lat=null;

            if($isGw){
                // Ping no more than once per minute per gateway
                $lastPingAt = $cache[$id]['last_ping_at'] ?? 0;
                $cachedLat  = $cache[$id]['last_ping_ms'] ?? null;
                if(($now - $lastPingAt) >= 60 || $cachedLat===null){
                    $lat=ping_host($d['ipAddress']??null);
                    $cache[$id]['last_ping_at']=$now;
                    $cache[$id]['last_ping_ms']=$lat;
                    $cache_changed=true;
                } else {
                    $lat=$cachedLat;
                }
                if($now%60===0){
                    $stmt=$db->prepare("INSERT INTO metrics (device_id,name,cpu,ram,temp,latency,online) VALUES (?,?,?,?,?,?,?)");
                    $stmt->bindValue(1,$id,SQLITE3_TEXT);
                    $stmt->bindValue(2,$name,SQLITE3_TEXT);
                    $stmt->bindValue(3,$cpu,SQLITE3_INTEGER);
                    $stmt->bindValue(4,$ram,SQLITE3_INTEGER);
                    $stmt->bindValue(5,$temp,SQLITE3_INTEGER);
                    $stmt->bindValue(6,$lat,SQLITE3_FLOAT);
                    $stmt->bindValue(7,$on?1:0,SQLITE3_INTEGER);
                    @$stmt->execute();
                }
            } else {
                // CPE: ping only if selected in this 3-minute window
                if (isset($cpe_ping_set[$id])) {
                    $cpe_lat = ping_host($d['ipAddress']??null);
                    $cache[$id]['last_cpe_ping_at'] = $now;
                    $cache[$id]['last_cpe_ping_ms'] = $cpe_lat;
                    $cache_changed = true;
                } else {
                    $cpe_lat = $cache[$id]['last_cpe_ping_ms'] ?? null;
                }
            }

            $sim=!empty($cache[$id]['simulate']);
            if($sim) $on=false;

            // Track offline start time to compute outage duration
            if(!isset($cache[$id])) $cache[$id]=[];
            if(!$on){
                if(empty($cache[$id]['offline_since'])){ $cache[$id]['offline_since']=$now; $cache_changed=true; }
                $offline_since=$cache[$id]['offline_since'];
                // Notify when gateway is offline: first after threshold, then every 10 minutes while still offline
                $threshold_met = ($now - ($cache[$id]['offline_since']??$now)) >= $FIRST_OFFLINE_THRESHOLD;
                $last_sent = $cache[$id]['gf_last_offline_notif'] ?? null;
                $should_repeat = ($last_sent && ($now - $last_sent) >= 600);
                if($isGw && $threshold_met && (!$last_sent || $should_repeat)){
                    @file_put_contents($CACHE_DIR.'/gotify_log.txt', date('c')." offline eval: id=$id name=$name threshold_met=$threshold_met last_sent=".($last_sent?:'null')." repeat=$should_repeat\n", FILE_APPEND);
                    if(send_gotify('Gateway Offline', $name.' is OFFLINE', 8)){
                        $cache[$id]['gf_last_offline_notif']=$now; $cache_changed=true;
                    } else {
                        @file_put_contents($CACHE_DIR.'/gotify_log.txt', date('c')." offline send_gotify returned false for id=$id name=$name\n", FILE_APPEND);
                    }
                }
            } else {
                if(!empty($cache[$id]['offline_since'])){ unset($cache[$id]['offline_since']); $cache_changed=true; }
                $offline_since=null;
                // If previously offline, send recovery notification
                if(!empty($prev_cache[$id]['offline_since']) && $isGw){
                    @file_put_contents($CACHE_DIR.'/gotify_log.txt', date('c')." online eval: id=$id name=$name\n", FILE_APPEND);
                    if(send_gotify('Gateway Online', $name.' is back ONLINE', 5)){
                        unset($cache[$id]['gf_last_offline_notif']); $cache_changed=true;
                    }
                } else {
                    if(isset($cache[$id]['gf_last_offline_notif'])){ unset($cache[$id]['gf_last_offline_notif']); $cache_changed=true; }
                }
            }

            $ack_until=$cache[$id]['ack_until']??null;

            $out[]=[
                'id'=>$id,'name'=>$name,'gateway'=>$isGw,
                'online'=>$on,'cpu'=>$cpu,'ram'=>$ram,'temp'=>$temp,'latency'=>$lat,
                'cpe_latency'=>$cpe_lat,
                'uptime'=>$uptime,
                'offline_since'=>$offline_since,
                'simulate'=>$sim,'ack_until'=>$ack_until
            ];
        }

        if($cache_changed){ file_put_contents($CACHE_FILE,json_encode($cache)); }
        echo json_encode(['devices'=>$out,'http'=>$http_code,'api_latency'=>$api_latency]); exit;
    }

    if($_GET['ajax']==='history' && !empty($_GET['id'])){
        $id=$_GET['id'];
        $stmt=$db->prepare("SELECT timestamp,cpu,ram,temp,latency FROM metrics WHERE device_id=? ORDER BY timestamp DESC LIMIT 1440");
        $stmt->bindValue(1,$id,SQLITE3_TEXT);
        $res=$stmt->execute();
        $rows=[];
        while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=$r;
        echo json_encode(array_reverse($rows)); exit;
    }

    if($_GET['ajax']==='gotifytest'){
        $ok = send_gotify('Test from UISP NOC','This is a test notification.', 5);
        echo json_encode(['ok'=>$ok?1:0]); exit;
    }

    if($_GET['ajax']==='ack' && !empty($_GET['id']) && !empty($_GET['dur'])){
        $id=$_GET['id']; $dur=$_GET['dur'];
        $durmap=['30m'=>1800,'1h'=>3600,'6h'=>21600,'8h'=>28800,'12h'=>43200];
        $cache[$id]['ack_until']=time()+($durmap[$dur]??1800);
        file_put_contents($CACHE_FILE,json_encode($cache));
        echo json_encode(['ok'=>1]); exit;
    }
    if($_GET['ajax']==='clear' && !empty($_GET['id'])){
        unset($cache[$_GET['id']]['ack_until']);
        file_put_contents($CACHE_FILE,json_encode($cache));
        echo json_encode(['ok'=>1]); exit;
    }
    if($_GET['ajax']==='simulate' && !empty($_GET['id'])){
        $cache[$_GET['id']]['simulate']=true;
        file_put_contents($CACHE_FILE,json_encode($cache));
        echo json_encode(['ok'=>1]); exit;
    }
    if($_GET['ajax']==='clearsim' && !empty($_GET['id'])){
        $did = $_GET['id'];
        if(isset($cache[$did]['simulate'])) unset($cache[$did]['simulate']);
        // Proactively clear any outage state created by simulation so UI snaps back immediately
        if(isset($cache[$did]['offline_since'])) unset($cache[$did]['offline_since']);
        if(isset($cache[$did]['gf_last_offline_notif'])) unset($cache[$did]['gf_last_offline_notif']);
        file_put_contents($CACHE_FILE,json_encode($cache));
        echo json_encode(['ok'=>1]); exit;
    }
    if($_GET['ajax']==='clearall'){
        foreach($cache as $k=>&$c){
            if(is_array($c)){
                if(array_key_exists('ack_until',$c)) unset($c['ack_until']);
            }
        }
        file_put_contents($CACHE_FILE,json_encode($cache));
        echo json_encode(['ok'=>1]); exit;
    }
}

// For main HTML: prevent caching so index.php updates are reflected immediately
if(!isset($_GET['ajax'])){
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>UISP NOC</title>
<link rel="stylesheet" href="assets/style.css?v=<?=$ASSET_VERSION?>">
</head>
<body>
<header>
  UISP NOC 
  <span id="overallSummary"></span>
  <button id="enableSoundBtn" class="btn-accent" onclick="enableSound()" style="float:right;margin-right:10px;">Enable Sound</button>
  <button onclick="clearAll()" style="float:right;margin-right:10px;">Clear All Acks</button>
</header>
<div class="tabs">
  <button class="tablink active" onclick="openTab('gateways')">Gateways</button>
  <button class="tablink" onclick="openTab('cpes')">CPEs</button>
</div>
<div id="gateways" class="tabcontent" style="display:block"><div id="gateGrid" class="grid"></div></div>
<div id="cpes" class="tabcontent" style="display:none"><div id="cpeGrid" class="grid"></div></div>
<footer id="footer"></footer>

<div id="historyModal" class="modal">
  <div class="modal-content">
    <h3 id="histTitle"></h3>
    <button class="modal-close" onclick="closeModal()" aria-label="Close">&times;</button>
    <canvas id="cpuChart"></canvas>
    <canvas id="ramChart"></canvas>
    <canvas id="tempChart"></canvas>
    <canvas id="latChart"></canvas>
    <button onclick="closeModal()">Close</button>
  </div>
</div>

<audio id="siren" src="buz.mp3?v=<?=$ASSET_VERSION?>" preload="auto"></audio>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/app.js?v=<?=$ASSET_VERSION?>"></script>
</body>
</html>
