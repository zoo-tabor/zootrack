<?php
/**
 * sqlite_to_mariadb.php
 * Exportuje data z zoo_db.sqlite do MariaDB (tabulky zootrack_*).
 *
 * Použití: spusťte jednorázově z prohlížeče nebo příkazové řádky.
 * Skrip je idempotentní – existující řádky přeskočí (INSERT IGNORE).
 *
 * Prerekvizity:
 *   - PHP s PDO, pdo_sqlite a pdo_mysql
 *   - zoo_db.sqlite ve stejné složce jako tento skript
 *   - .env s DB_HOST, DB_NAME, DB_USER, DB_PASS ve stejné složce
 *   - tabulky zootrack_* musí existovat (spustit migraci 009 před tím)
 */

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

// ── Helpers ────────────────────────────────────────────────────────────────

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

function log_msg(string $msg): void {
    echo $msg . "\n";
    flush();
    ob_flush();
}

// ── Load credentials ───────────────────────────────────────────────────────

$env = readEnv(__DIR__ . '/.env');
$required = ['DB_HOST','DB_NAME','DB_USER','DB_PASS'];
foreach ($required as $k) {
    if (empty($env[$k])) die("Chybí klíč $k v .env\n");
}

// ── Connect SQLite ─────────────────────────────────────────────────────────

$sqlitePath = __DIR__ . '/zoo_db.sqlite';
if (!file_exists($sqlitePath)) die("Soubor zoo_db.sqlite nenalezen v " . __DIR__ . "\n");

try {
    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("SQLite chyba: " . $e->getMessage() . "\n");
}

// ── Connect MariaDB ────────────────────────────────────────────────────────

try {
    $dsn = "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4";
    $mysql = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
} catch (Exception $e) {
    die("MariaDB chyba: " . $e->getMessage() . "\n");
}

log_msg("=== ZooTrack SQLite → MariaDB export ===\n");

// ── 1. Institutions ────────────────────────────────────────────────────────

log_msg("--- institutions ---");
$rows = $sqlite->query('SELECT * FROM institutions')->fetchAll();
log_msg("Nalezeno " . count($rows) . " institucí v SQLite.");

$ins = $mysql->prepare("
    INSERT IGNORE INTO `zootrack_institutions`
        (id, country, subdivision, city, institution, institution_aliases,
         institution_type, website, eaza_status, other_memberships,
         kea_verdict, kea_confidence, kea_evidence, notes, created_at, updated_at)
    VALUES
        (:id,:country,:subdivision,:city,:institution,:institution_aliases,
         :institution_type,:website,:eaza_status,:other_memberships,
         :kea_verdict,:kea_confidence,:kea_evidence,:notes,
         COALESCE(:created_at, NOW()), COALESCE(:updated_at, NOW()))
");

$done = 0;
foreach ($rows as $r) {
    $ins->execute([
        ':id'                  => $r['id'],
        ':country'             => $r['country'] ?? '',
        ':subdivision'         => $r['subdivision'] ?? null,
        ':city'                => $r['city'] ?? null,
        ':institution'         => $r['institution'] ?? '',
        ':institution_aliases' => $r['institution_aliases'] ?? null,
        ':institution_type'    => $r['institution_type'] ?? null,
        ':website'             => $r['website'] ?? null,
        ':eaza_status'         => $r['eaza_status'] ?? null,
        ':other_memberships'   => $r['other_memberships'] ?? null,
        ':kea_verdict'         => $r['kea_verdict'] ?? null,
        ':kea_confidence'      => $r['kea_confidence'] ?? null,
        ':kea_evidence'        => $r['kea_evidence'] ?? null,
        ':notes'               => $r['notes'] ?? null,
        ':created_at'          => $r['created_at'] ?? null,
        ':updated_at'          => $r['updated_at'] ?? null,
    ]);
    $done++;
    if ($done % 500 === 0) log_msg("  ... $done řádků");
}
log_msg("Importováno: $done institucí.\n");

// ── 2. Species ─────────────────────────────────────────────────────────────

log_msg("--- species ---");
$rows = $sqlite->query('SELECT * FROM species')->fetchAll();
log_msg("Nalezeno " . count($rows) . " druhů v SQLite.");

// We preserve original IDs so holdings FK will match
$ins = $mysql->prepare("
    INSERT IGNORE INTO `zootrack_species`
        (id, scientific_name, common_name_cs, common_name_en, common_name_de,
         taxon_class, taxon_order, taxon_family, iucn_status, cites_appendix,
         eep, notes, created_at)
    VALUES
        (:id,:scientific_name,:common_name_cs,:common_name_en,:common_name_de,
         :taxon_class,:taxon_order,:taxon_family,:iucn_status,:cites_appendix,
         :eep,:notes,COALESCE(:created_at, NOW()))
");

$done = 0;
$maxId = 0;
foreach ($rows as $r) {
    $ins->execute([
        ':id'              => (int)$r['id'],
        ':scientific_name' => $r['scientific_name'] ?? '',
        ':common_name_cs'  => $r['common_name_cs'] ?? null,
        ':common_name_en'  => $r['common_name_en'] ?? null,
        ':common_name_de'  => $r['common_name_de'] ?? null,
        ':taxon_class'     => $r['taxon_class'] ?? null,
        ':taxon_order'     => $r['taxon_order'] ?? null,
        ':taxon_family'    => $r['taxon_family'] ?? null,
        ':iucn_status'     => $r['iucn_status'] ?? null,
        ':cites_appendix'  => $r['cites_appendix'] ?? null,
        ':eep'             => (int)($r['eep'] ?? 0),
        ':notes'           => $r['notes'] ?? null,
        ':created_at'      => $r['created_at'] ?? null,
    ]);
    $done++;
    if ((int)$r['id'] > $maxId) $maxId = (int)$r['id'];
}
log_msg("Importováno: $done druhů.");

// Reset AUTO_INCREMENT so new inserts continue after imported IDs
$mysql->exec("ALTER TABLE `zootrack_species` AUTO_INCREMENT = " . ($maxId + 1));
log_msg("AUTO_INCREMENT nastaven na " . ($maxId + 1) . ".\n");

// ── 3. Holdings ────────────────────────────────────────────────────────────

log_msg("--- holdings ---");
$rows = $sqlite->query('SELECT * FROM holdings')->fetchAll();
log_msg("Nalezeno " . count($rows) . " záznamů v SQLite.");

$ins = $mysql->prepare("
    INSERT IGNORE INTO `zootrack_holdings`
        (id, institution_id, species_id, holding_verdict, sex_ratio, count_note,
         breeding_verdict, last_offspring_year, confidence, source_type, source_url,
         evidence_date, evidence_summary, notes, created_at, updated_at)
    VALUES
        (:id,:institution_id,:species_id,:holding_verdict,:sex_ratio,:count_note,
         :breeding_verdict,:last_offspring_year,:confidence,:source_type,:source_url,
         :evidence_date,:evidence_summary,:notes,
         COALESCE(:created_at, NOW()), COALESCE(:updated_at, NOW()))
");

$done = 0;
$maxId = 0;
foreach ($rows as $r) {
    // evidence_date: SQLite stores as TEXT, MariaDB as DATE — empty string → null
    $ed = !empty($r['evidence_date']) ? $r['evidence_date'] : null;

    $ins->execute([
        ':id'                  => (int)$r['id'],
        ':institution_id'      => $r['institution_id'],
        ':species_id'          => (int)$r['species_id'],
        ':holding_verdict'     => $r['holding_verdict'] ?? 'unknown',
        ':sex_ratio'           => $r['sex_ratio'] ?? null,
        ':count_note'          => $r['count_note'] ?? null,
        ':breeding_verdict'    => $r['breeding_verdict'] ?? 'unknown',
        ':last_offspring_year' => $r['last_offspring_year'] ?? null,
        ':confidence'          => $r['confidence'] ?? 'medium',
        ':source_type'         => $r['source_type'] ?? null,
        ':source_url'          => $r['source_url'] ?? null,
        ':evidence_date'       => $ed,
        ':evidence_summary'    => $r['evidence_summary'] ?? null,
        ':notes'               => $r['notes'] ?? null,
        ':created_at'          => $r['created_at'] ?? null,
        ':updated_at'          => $r['updated_at'] ?? null,
    ]);
    $done++;
    if ((int)$r['id'] > $maxId) $maxId = (int)$r['id'];
}
log_msg("Importováno: $done záznamů.");

if ($maxId > 0) {
    $mysql->exec("ALTER TABLE `zootrack_holdings` AUTO_INCREMENT = " . ($maxId + 1));
    log_msg("AUTO_INCREMENT nastaven na " . ($maxId + 1) . ".");
}

// ── Summary ────────────────────────────────────────────────────────────────

log_msg("\n=== Hotovo! ===");
$ci = (int)$mysql->query('SELECT COUNT(*) FROM zootrack_institutions')->fetchColumn();
$cs = (int)$mysql->query('SELECT COUNT(*) FROM zootrack_species')->fetchColumn();
$ch = (int)$mysql->query('SELECT COUNT(*) FROM zootrack_holdings')->fetchColumn();
log_msg("MariaDB nyní obsahuje:");
log_msg("  zootrack_institutions : $ci řádků");
log_msg("  zootrack_species      : $cs řádků");
log_msg("  zootrack_holdings     : $ch řádků");
