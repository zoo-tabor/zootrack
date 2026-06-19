<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── DB connection (MariaDB) ─────────────────────────────────────────────────

function readEnv(string $path): array {
    $vars = [];
    if (!file_exists($path)) return $vars;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (ltrim($line)[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $vars[trim($parts[0])] = trim($parts[1]);
    }
    return $vars;
}

$env = readEnv(__DIR__ . '/.env');

try {
    $dsn = "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4";
    $db  = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['error' => 'DB error: ' . $e->getMessage()]));
}

$body   = [];
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
}

$action = $_GET['action'] ?? $body['action'] ?? '';

try {
    switch ($action) {
        case 'stats':          echo json_encode(getStats($db));               break;
        case 'countries':      echo json_encode(getCountries($db));           break;
        case 'inst_types':     echo json_encode(getInstTypes($db));           break;
        case 'institutions':   echo json_encode(getInstitutions($db));        break;
        case 'institution':    echo json_encode(getInstitution($db));         break;
        case 'species':        echo json_encode(getSpecies($db));             break;
        case 'save_species':   echo json_encode(saveSpecies($db,$body));      break;
        case 'save_holding':   echo json_encode(saveHolding($db,$body));      break;
        case 'delete_holding': echo json_encode(deleteHolding($db,$body));    break;
        case 'update_inst':    echo json_encode(updateInst($db,$body));       break;
        case 'add_institution':echo json_encode(addInstitution($db,$body));   break;
        case 'cites_lookup':   echo json_encode(citesLookup($env));           break;
        case 'cites_update_all': echo json_encode(citesUpdateAll($db,$env)); break;
        default:               echo json_encode(['error' => "Unknown action: $action"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Stats ──────────────────────────────────────────────────────────────────
function getStats(PDO $db): array {
    $total     = (int)$db->query('SELECT COUNT(*) FROM `zootrack_institutions`')->fetchColumn();
    $with_data = (int)$db->query('SELECT COUNT(DISTINCT institution_id) FROM `zootrack_holdings`')->fetchColumn();
    $sp_count  = (int)$db->query('SELECT COUNT(*) FROM `zootrack_species`')->fetchColumn();
    $h_count   = (int)$db->query('SELECT COUNT(*) FROM `zootrack_holdings`')->fetchColumn();
    $eaza      = (int)$db->query("SELECT COUNT(*) FROM `zootrack_institutions` WHERE eaza_status LIKE 'EAZA%'")->fetchColumn();
    $confirmed = (int)$db->query("SELECT COUNT(*) FROM `zootrack_holdings` WHERE holding_verdict='confirmed_current'")->fetchColumn();
    return compact('total','with_data','sp_count','h_count','eaza','confirmed');
}

function getCountries(PDO $db): array {
    return $db->query('SELECT DISTINCT country FROM `zootrack_institutions` ORDER BY country')
               ->fetchAll(PDO::FETCH_COLUMN);
}

function getInstTypes(PDO $db): array {
    return $db->query("SELECT DISTINCT institution_type FROM `zootrack_institutions` WHERE institution_type!='' ORDER BY institution_type")
               ->fetchAll(PDO::FETCH_COLUMN);
}

// ── Institutions list (paginated, filtered) ────────────────────────────────
function getInstitutions(PDO $db): array {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $per     = min(100, max(10, (int)($_GET['per'] ?? 50)));
    $offset  = ($page - 1) * $per;
    $q       = trim($_GET['q']           ?? '');
    $country = trim($_GET['country']     ?? '');
    $eaza    = trim($_GET['eaza']        ?? '');
    $sid     = (int)($_GET['species_id'] ?? 0);
    $breed   = trim($_GET['breeding']    ?? '');

    $sortMap = [
        'institution'      => 'i.institution',
        'country'          => 'i.country, i.city, i.institution',
        'eaza_status'      => 'i.eaza_status, i.country, i.institution',
        'institution_type' => 'i.institution_type, i.country, i.institution',
        'holding_count'    => 'holding_count',
    ];
    $sortKey = trim($_GET['sort'] ?? '');
    $sortDir = strtoupper(trim($_GET['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
    $orderBy = isset($sortMap[$sortKey]) ? $sortMap[$sortKey].' '.$sortDir : 'i.country, i.city, i.institution';

    $w = ['1=1']; $p = [];
    if ($q) {
        $w[] = "(i.institution LIKE :q OR i.city LIKE :q OR i.institution_aliases LIKE :q OR i.id LIKE :q)";
        $p[':q'] = "%$q%";
    }
    if ($country) { $w[] = 'i.country = :country'; $p[':country'] = $country; }
    if ($eaza === 'EAZA')    { $w[] = "i.eaza_status LIKE 'EAZA%'"; }
    elseif ($eaza === 'non') { $w[] = "i.eaza_status NOT LIKE 'EAZA%'"; }
    if ($sid > 0) {
        if ($breed) {
            $w[] = 'EXISTS (SELECT 1 FROM `zootrack_holdings` h WHERE h.institution_id=i.id AND h.species_id=:sid AND h.breeding_verdict=:breed)';
            $p[':breed'] = $breed;
        } else {
            $w[] = 'EXISTS (SELECT 1 FROM `zootrack_holdings` h WHERE h.institution_id=i.id AND h.species_id=:sid)';
        }
        $p[':sid'] = $sid;
    }

    $where = implode(' AND ', $w);

    $cStmt = $db->prepare("SELECT COUNT(*) FROM `zootrack_institutions` i WHERE $where");
    $cStmt->execute($p);
    $total = (int)$cStmt->fetchColumn();

    $hJoin = $sid > 0
        ? "LEFT JOIN `zootrack_holdings` hf ON hf.institution_id=i.id AND hf.species_id=:sid2"
        : "";
    $hCols = $sid > 0
        ? ", hf.holding_verdict, hf.breeding_verdict, hf.confidence, hf.source_type, hf.source_url, hf.evidence_date, hf.evidence_summary"
        : ", NULL as holding_verdict, NULL as breeding_verdict, NULL as confidence, NULL as source_type, NULL as source_url, NULL as evidence_date, NULL as evidence_summary";

    $sql = "SELECT i.id, i.country, i.city, i.institution, i.institution_type,
                   i.eaza_status, i.website,
                   (SELECT COUNT(*) FROM `zootrack_holdings` h WHERE h.institution_id=i.id) as holding_count
                   $hCols
            FROM `zootrack_institutions` i $hJoin WHERE $where
            ORDER BY $orderBy
            LIMIT :per OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($p as $k => $v) $stmt->bindValue($k, $v);
    if ($sid > 0) $stmt->bindValue(':sid2', $sid, PDO::PARAM_INT);
    $stmt->bindValue(':per',    $per,    PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    return ['items'=>$items,'total'=>$total,'page'=>$page,'per'=>$per,'pages'=>(int)ceil($total/$per)];
}

// ── Single institution with holdings ──────────────────────────────────────
function getInstitution(PDO $db): array {
    $id = trim($_GET['id'] ?? '');
    if (!$id) return ['error'=>'Missing id'];
    $stmt = $db->prepare('SELECT * FROM `zootrack_institutions` WHERE id=?');
    $stmt->execute([$id]);
    $inst = $stmt->fetch();
    if (!$inst) return ['error'=>'Not found'];

    $stmt = $db->prepare('
        SELECT h.*, s.scientific_name, s.common_name_cs, s.common_name_en,
               s.iucn_status, s.cites_appendix, s.eep, s.taxon_class
        FROM `zootrack_holdings` h
        JOIN `zootrack_species` s ON h.species_id=s.id
        WHERE h.institution_id=? ORDER BY s.scientific_name
    ');
    $stmt->execute([$id]);
    $inst['holdings'] = $stmt->fetchAll();
    return $inst;
}

// ── Species ────────────────────────────────────────────────────────────────
function getSpecies(PDO $db): array {
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT s.*, (SELECT COUNT(*) FROM `zootrack_holdings` h WHERE h.species_id=s.id) as holding_count
            FROM `zootrack_species` s";
    if ($q) {
        $sql .= " WHERE s.scientific_name LIKE :q OR s.common_name_cs LIKE :q OR s.common_name_en LIKE :q OR s.common_name_de LIKE :q";
        $stmt = $db->prepare($sql . " ORDER BY s.scientific_name");
        $stmt->execute([':q'=>"%$q%"]);
    } else {
        $stmt = $db->query($sql . " ORDER BY s.scientific_name");
    }
    return $stmt->fetchAll();
}

function saveSpecies(PDO $db, array $body): array {
    $id  = (int)($body['id'] ?? 0);
    $sci = trim($body['scientific_name'] ?? '');
    if (!$sci) return ['error'=>'scientific_name required'];

    $fields = ['scientific_name','common_name_cs','common_name_en','common_name_de',
               'taxon_class','taxon_order','taxon_family','iucn_status','cites_appendix','eep','notes'];

    if ($id > 0) {
        $sets = implode(', ', array_map(function($f){ return "`$f`=:$f"; }, $fields));
        $stmt = $db->prepare("UPDATE `zootrack_species` SET $sets WHERE id=:id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    } else {
        $cols = '`' . implode('`,`', $fields) . '`';
        $vals = ':' . implode(',:', $fields);
        $stmt = $db->prepare("INSERT INTO `zootrack_species` ($cols) VALUES ($vals)");
    }
    foreach ($fields as $f) {
        if ($f === 'eep') $stmt->bindValue(":$f", (int)($body[$f]??0), PDO::PARAM_INT);
        else              $stmt->bindValue(":$f", $body[$f] ?? '');
    }
    $stmt->execute();
    return ['ok'=>true, 'id'=> $id>0 ? $id : (int)$db->lastInsertId()];
}

// ── Holdings ───────────────────────────────────────────────────────────────
function saveHolding(PDO $db, array $body): array {
    $id  = (int)($body['id'] ?? 0);
    $iid = trim($body['institution_id'] ?? '');
    $sid = (int)($body['species_id'] ?? 0);
    if (!$iid || !$sid) return ['error'=>'institution_id and species_id required'];

    if (!$id) {
        $s = $db->prepare('SELECT id FROM `zootrack_holdings` WHERE institution_id=? AND species_id=?');
        $s->execute([$iid,$sid]);
        $ex = $s->fetch();
        if ($ex) $id = (int)$ex['id'];
    }

    $now = date('Y-m-d H:i:s');
    $fields = ['holding_verdict','sex_ratio','count_note','breeding_verdict',
               'last_offspring_year','confidence','source_type','source_url',
               'evidence_date','evidence_summary','notes'];

    if ($id) {
        $sets = implode(',', array_map(function($f){ return "`$f`=:$f"; }, $fields));
        $stmt = $db->prepare("UPDATE `zootrack_holdings` SET $sets, updated_at=:now WHERE id=:id");
        $stmt->bindValue(':id',  $id,  PDO::PARAM_INT);
        $stmt->bindValue(':now', $now);
    } else {
        $af   = array_merge(['institution_id','species_id'], $fields, ['created_at','updated_at']);
        $cols = '`' . implode('`,`', $af) . '`';
        $vals = ':' . implode(',:', $af);
        $stmt = $db->prepare("INSERT INTO `zootrack_holdings` ($cols) VALUES ($vals)");
        $stmt->bindValue(':institution_id', $iid);
        $stmt->bindValue(':species_id',     $sid, PDO::PARAM_INT);
        $stmt->bindValue(':created_at',     $now);
        $stmt->bindValue(':updated_at',     $now);
    }
    foreach ($fields as $f) $stmt->bindValue(":$f", $body[$f] ?? '');
    $stmt->execute();
    if (!$id) $id = (int)$db->lastInsertId();
    return ['ok'=>true, 'id'=>$id];
}

function deleteHolding(PDO $db, array $body): array {
    $id = (int)($body['id'] ?? 0);
    if (!$id) return ['error'=>'id required'];
    $db->prepare('DELETE FROM `zootrack_holdings` WHERE id=?')->execute([$id]);
    return ['ok'=>true];
}

function addInstitution(PDO $db, array $body): array {
    $id          = trim($body['id'] ?? '');
    $institution = trim($body['institution'] ?? '');
    $country     = trim($body['country'] ?? '');
    $city        = trim($body['city'] ?? '');
    if (!$id || !$institution || !$country || !$city)
        return ['error' => 'id, institution, country and city are required'];

    $check = $db->prepare('SELECT id FROM `zootrack_institutions` WHERE id=?');
    $check->execute([$id]);
    if ($check->fetch()) return ['error' => "ID '$id' již existuje"];

    $db->prepare("
        INSERT INTO `zootrack_institutions`
            (id, country, subdivision, city, institution, institution_aliases,
             institution_type, website, eaza_status, other_memberships, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $id, $country,
        trim($body['subdivision'] ?? ''),
        $city, $institution,
        trim($body['institution_aliases'] ?? ''),
        trim($body['institution_type'] ?? ''),
        trim($body['website'] ?? ''),
        trim($body['eaza_status'] ?? 'non-EAZA'),
        trim($body['other_memberships'] ?? ''),
        trim($body['notes'] ?? ''),
    ]);
    return ['ok' => true, 'id' => $id];
}

function updateInst(PDO $db, array $body): array {
    $id    = trim($body['id'] ?? '');
    $notes = trim($body['notes'] ?? '');
    if (!$id) return ['error'=>'id required'];
    $db->prepare("UPDATE `zootrack_institutions` SET notes=?, updated_at=NOW() WHERE id=?")
       ->execute([$notes, $id]);
    return ['ok'=>true];
}

// ── CITES ──────────────────────────────────────────────────────────────────
function citesApiQuery(string $name, string $token): array {
    $url = 'https://api.speciesplus.net/api/v1/taxon_concepts?name=' . urlencode($name);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["X-Authentication-Token: $token", "Accept: application/json"],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body   = curl_exec($ch);
    $err    = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ['error' => $err];
    // Retry once on rate limit
    if ($status === 429) {
        sleep(12);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["X-Authentication-Token: $token", "Accept: application/json"],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body   = curl_exec($ch);
        $err    = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) return ['error' => $err];
    }
    if ($status !== 200) return ['error' => "API HTTP $status", 'raw' => substr($body, 0, 300)];

    $data = json_decode($body, true);
    if (!isset($data['taxon_concepts'])) {
        return ['error' => 'Unexpected response', 'raw' => substr($body, 0, 300)];
    }

    // Hledáme přijatý taxon (name_status=A) se shodným jménem
    $accepted = null;
    foreach ($data['taxon_concepts'] as $tc) {
        if (strcasecmp($tc['full_name'] ?? '', $name) !== 0) continue;
        if (($tc['name_status'] ?? '') === 'A') { $accepted = $tc; break; }
    }
    // Fallback: první výsledek bez ohledu na name_status
    if ($accepted === null && !empty($data['taxon_concepts'])) {
        $accepted = $data['taxon_concepts'][0];
    }
    if ($accepted === null) return ['appendix' => '', 'note' => 'not found'];

    // cites_listing je null pro split-listed taxony — sestavíme ho z cites_listings[]
    $listing = $accepted['cites_listing'] ?? null;
    if ($listing === null || $listing === '') {
        $parts = [];
        foreach ($accepted['cites_listings'] ?? [] as $l) {
            $app = $l['appendix'] ?? '';
            if ($app !== '' && !in_array($app, $parts, true)) $parts[] = $app;
        }
        $listing = implode('/', $parts);
    }

    return [
        'appendix'        => $listing,
        'taxon_id'        => $accepted['id'] ?? null,
        'scientific_name' => $accepted['full_name'] ?? '',
        'rank'            => $accepted['rank'] ?? '',
    ];
}

function citesLookup(array $env): array {
    $name  = trim($_GET['name'] ?? '');
    if (!$name) return ['error' => 'name required'];
    $token = $env['api_token_speciesplus'] ?? '';
    if (!$token) return ['error' => 'API token not configured'];
    return citesApiQuery($name, $token);
}

function citesUpdateAll(PDO $db, array $env): array {
    $token = $env['api_token_speciesplus'] ?? '';
    if (!$token) return ['error' => 'API token not configured'];
    $species = $db->query('SELECT id, scientific_name FROM `zootrack_species`')->fetchAll();
    $updated = 0; $errors = [];
    foreach ($species as $sp) {
        $res = citesApiQuery($sp['scientific_name'], $token);
        if (isset($res['error'])) { $errors[] = $sp['scientific_name'].': '.$res['error'].' | raw: '.($res['raw']??'–'); continue; }
        $db->prepare('UPDATE `zootrack_species` SET cites_appendix=? WHERE id=?')
           ->execute([$res['appendix'], $sp['id']]);
        $updated++;
        usleep(2100000);
    }
    return ['ok' => true, 'updated' => $updated, 'errors' => $errors];
}
