#!/usr/bin/env python3
"""
Create zoo_db.sqlite from kea expansion master CSV + baseline.
Run once locally, then upload db.sqlite alongside api.php + index.html to server.

Usage:  python create_db.py
Output: zoo_db.sqlite  (same directory as this script)
"""
import csv, sqlite3
from pathlib import Path

ROOT     = Path(__file__).parent.parent          # outputs/
MASTER   = ROOT / "kea_europe_expansion/merged/kea_europe_expansion_final_master.csv"
BASELINE = ROOT / "kea_all_results_with_logged_in_fb_update_utf8.csv"
DB       = Path(__file__).parent / "zoo_db.sqlite"

if DB.exists():
    DB.unlink()

con = sqlite3.connect(DB)
con.row_factory = sqlite3.Row
db  = con.cursor()

# ── Schema ────────────────────────────────────────────────────────────
db.executescript("""
PRAGMA foreign_keys = ON;

CREATE TABLE institutions (
    id                  TEXT PRIMARY KEY,
    country             TEXT NOT NULL,
    subdivision         TEXT,
    city                TEXT,
    institution         TEXT NOT NULL,
    institution_aliases TEXT,
    institution_type    TEXT,
    website             TEXT,
    eaza_status         TEXT,
    other_memberships   TEXT,
    kea_verdict         TEXT,
    kea_confidence      TEXT,
    kea_evidence        TEXT,
    notes               TEXT,
    created_at          TEXT DEFAULT (datetime('now')),
    updated_at          TEXT DEFAULT (datetime('now'))
);

CREATE TABLE species (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    scientific_name TEXT NOT NULL UNIQUE,
    common_name_cs  TEXT,
    common_name_en  TEXT,
    common_name_de  TEXT,
    taxon_class     TEXT,
    taxon_order     TEXT,
    taxon_family    TEXT,
    iucn_status     TEXT,
    cites_appendix  TEXT,
    eep             INTEGER DEFAULT 0,
    notes           TEXT,
    created_at      TEXT DEFAULT (datetime('now'))
);

CREATE TABLE holdings (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    institution_id      TEXT NOT NULL REFERENCES institutions(id),
    species_id          INTEGER NOT NULL REFERENCES species(id),
    holding_verdict     TEXT DEFAULT 'unknown',
    sex_ratio           TEXT,
    count_note          TEXT,
    breeding_verdict    TEXT DEFAULT 'unknown',
    last_offspring_year TEXT,
    confidence          TEXT DEFAULT 'medium',
    source_type         TEXT,
    source_url          TEXT,
    evidence_date       TEXT,
    evidence_summary    TEXT,
    notes               TEXT,
    created_at          TEXT DEFAULT (datetime('now')),
    updated_at          TEXT DEFAULT (datetime('now')),
    UNIQUE(institution_id, species_id)
);

CREATE INDEX idx_h_inst    ON holdings(institution_id);
CREATE INDEX idx_h_species ON holdings(species_id);
CREATE INDEX idx_i_country ON institutions(country);
CREATE INDEX idx_i_eaza    ON institutions(eaza_status);
""")

# ── Import institutions ───────────────────────────────────────────────
def c(s): return (s or "").strip()

def eaza_norm(v):
    v = c(v)
    if v.upper().startswith("EAZA"): return v
    return "non-EAZA"

print("Importing institutions …")
with open(MASTER, encoding="utf-8-sig") as f:
    for row in csv.DictReader(f):
        notes = "\n".join(filter(None, [c(row.get("agent_notes","")), c(row.get("review_notes",""))]))
        db.execute("""
            INSERT OR IGNORE INTO institutions
            (id, country, subdivision, city, institution, institution_aliases,
             institution_type, website, eaza_status, other_memberships,
             kea_verdict, kea_confidence, kea_evidence, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        """, (
            c(row["record_id"]), c(row["country"]), c(row["subdivision"]),
            c(row["city"]), c(row["institution"]), c(row["institution_aliases"]),
            c(row["institution_type"]), c(row["website"]),
            eaza_norm(row.get("eaza_status_final","")),
            c(row.get("other_association_memberships","")),
            c(row.get("final_kea_presence_verdict","")),
            c(row.get("final_confidence","")),
            c(row.get("final_evidence_summary","")),
            notes,
        ))

con.commit()
print(f"  {db.execute('SELECT COUNT(*) FROM institutions').fetchone()[0]} institutions")

# ── Starter species ───────────────────────────────────────────────────
SPECIES = [
    # scientific_name, cs, en, de, class, order, family, iucn, cites, eep
    ("Nestor notabilis","Nestor kea","Kea","Kea","Aves","Psittaciformes","Nestoridae","EN","II",1),
    ("Nestor meridionalis","Nestor kaka","Kākā","Kaka","Aves","Psittaciformes","Nestoridae","EN","II",1),
    ("Strigops habroptilus","Kakapo","Kakapo","Kakapo","Aves","Psittaciformes","Strigopidae","CR","I",1),
    ("Psittacus erithacus","Šedý papoušek","Grey Parrot","Graupapagei","Aves","Psittaciformes","Psittacidae","EN","I",1),
    ("Ara ararauna","Ara ararauna","Blue-and-yellow Macaw","Blaugelber Ara","Aves","Psittaciformes","Psittacidae","LC","II",0),
    ("Ara macao","Ara šarlatová","Scarlet Macaw","Hellroter Ara","Aves","Psittaciformes","Psittacidae","LC","II",0),
    ("Ara chloropterus","Ara zelená","Red-and-green Macaw","Grünflügelara","Aves","Psittaciformes","Psittacidae","LC","II",0),
    ("Ara ambiguus","Ara velká zelená","Great Green Macaw","Buffons Ara","Aves","Psittaciformes","Psittacidae","EN","I",1),
    ("Anodorhynchus hyacinthinus","Ara hyacintová","Hyacinth Macaw","Hyazinthara","Aves","Psittaciformes","Psittacidae","VU","I",1),
    ("Cyanopsitta spixii","Ara Spixova","Spix's Macaw","Spixara","Aves","Psittaciformes","Psittacidae","EW","I",1),
    ("Guaruba guarouba","Zlatý parakét","Golden Parakeet","Goldsittich","Aves","Psittaciformes","Psittacidae","VU","I",1),
    ("Rhynchopsitta pachyrhyncha","Ara tlustošárková","Thick-billed Parrot","Dickschnabelpapagei","Aves","Psittaciformes","Psittacidae","EN","I",1),
    ("Cacatua moluccensis","Kakadu molucký","Salmon-crested Cockatoo","Molukkenkakadu","Aves","Psittaciformes","Cacatuidae","VU","I",1),
    ("Cacatua galerita","Kakadu žlutočapkový","Sulphur-crested Cockatoo","Gelbhaubenkakadu","Aves","Psittaciformes","Cacatuidae","LC","II",0),
    ("Cacatua leadbeateri","Kakadu Mitchellův","Major Mitchell's Cockatoo","Inkakakadu","Aves","Psittaciformes","Cacatuidae","LC","II",1),
    ("Cacatua ophthalmica","Kakadu modroočkový","Blue-eyed Cockatoo","Blauaugenkakadu","Aves","Psittaciformes","Cacatuidae","VU","II",1),
    ("Probosciger aterrimus","Kakadu palmový","Palm Cockatoo","Palmkakadu","Aves","Psittaciformes","Cacatuidae","LC","I",1),
    ("Calyptorhynchus banksii","Kakatuín Banksův","Red-tailed Black Cockatoo","Bankskakadu","Aves","Psittaciformes","Cacatuidae","LC","II",1),
    ("Calyptorhynchus latirostris","Kakatuín Carnaby","Carnaby's Black Cockatoo","Weißohrenkakadu","Aves","Psittaciformes","Cacatuidae","EN","II",1),
    ("Amazona oratrix","Amazoňan žlutohlavý","Yellow-headed Amazon","Gelbkopfamazone","Aves","Psittaciformes","Psittacidae","EN","I",1),
    ("Amazona aestiva","Amazoňan modrý","Turquoise-fronted Amazon","Blaustirnamazone","Aves","Psittaciformes","Psittacidae","LC","II",0),
    ("Eos histrio","Lori červenočerný","Red-and-blue Lory","Talaut-Lori","Aves","Psittaciformes","Psittaculidae","EN","II",1),
    ("Psittrichas fulgidus","Papoušek Pesquetův","Pesquet's Parrot","Pesquetpapagei","Aves","Psittaciformes","Psittaculidae","VU","II",1),
    ("Eclectus roratus","Eklektus","Eclectus Parrot","Edelpapagei","Aves","Psittaciformes","Psittaculidae","LC","II",0),
    ("Polytelis swainsonii","Polytelis Swainsonova","Superb Parrot","Barrabandpapagei","Aves","Psittaciformes","Psittaculidae","LC","II",1),
    ("Spheniscus demersus","Tučňák brýlový","African Penguin","Brillenpinguin","Aves","Sphenisciformes","Spheniscidae","EN","II",1),
    ("Spheniscus humboldti","Tučňák Humboldtův","Humboldt Penguin","Humboldtpinguin","Aves","Sphenisciformes","Spheniscidae","VU","I",1),
    ("Balearica regulorum","Jeřáb paví","Grey Crowned Crane","Graukranich","Aves","Gruiformes","Gruidae","EN","II",1),
    ("Phoenicopterus roseus","Plameňák růžový","Greater Flamingo","Rosaflamingo","Aves","Phoenicopteriformes","Phoenicopteridae","LC","II",0),
    ("Panthera tigris sumatrae","Tygr sumaterský","Sumatran Tiger","Sumatra-Tiger","Mammalia","Carnivora","Felidae","CR","I",1),
    ("Acinonyx jubatus","Gepard štíhlý","Cheetah","Gepard","Mammalia","Carnivora","Felidae","VU","I",1),
    ("Neofelis nebulosa","Levhart oblačný","Clouded Leopard","Nebelparder","Mammalia","Carnivora","Felidae","VU","I",1),
    ("Gorilla gorilla gorilla","Gorila nížinná","Western Lowland Gorilla","Westl. Flachlandgorilla","Mammalia","Primates","Hominidae","CR","I",1),
    ("Pan troglodytes","Šimpanz učenlivý","Common Chimpanzee","Schimpanse","Mammalia","Primates","Hominidae","EN","I",1),
    ("Elephas maximus","Slon indický","Asian Elephant","Asiatischer Elefant","Mammalia","Proboscidea","Elephantidae","EN","I",1),
]

print("Inserting starter species …")
for s in SPECIES:
    db.execute("""
        INSERT OR IGNORE INTO species
        (scientific_name,common_name_cs,common_name_en,common_name_de,
         taxon_class,taxon_order,taxon_family,iucn_status,cites_appendix,eep)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    """, s)

con.commit()
print(f"  {db.execute('SELECT COUNT(*) FROM species').fetchone()[0]} species")

# ── Kea holdings from baseline ─────────────────────────────────────────
kea_id = db.execute("SELECT id FROM species WHERE scientific_name='Nestor notabilis'").fetchone()[0]

def final_v(row, field):
    for pfx in ("post_login_fb_", "post_social_", ""):
        v = c(row.get(pfx + field, ""))
        if v: return v
    return ""

HOLD_MAP = {
    "confirmed_current": "confirmed_current",
    "likely_current":    "likely_current",
    "probable_current":  "likely_current",
    "unclear":           "unclear",
    "not_current":       "not_current",
    "historical_only":   "historical",
}
BREED_MAP = {
    "confirmed_offspring":          "confirmed",
    "likely_offspring_zims_signal": "likely",
    "likely_offspring":             "likely",
    "no_public_evidence":           "no_evidence",
    "no_evidence_or_unlikely":      "no_evidence",
    "unclear":                      "unknown",
}

print("Importing kea holdings from baseline …")
n = 0
with open(BASELINE, encoding="utf-8-sig") as f:
    for row in csv.DictReader(f):
        iid = c(row.get("id",""))
        if not iid: continue
        hv = HOLD_MAP.get(final_v(row,"current_holding_verdict"), "unknown")
        bv = BREED_MAP.get(final_v(row,"breeding_verdict"),        "unknown")
        conf = final_v(row,"confidence")
        year = c(row.get("post_login_fb_last_offspring_year","")) or c(row.get("last_offspring_year",""))
        fb_url  = c(row.get("login_fb_best_url",""))
        web_url = c(row.get("source_urls","")).split(";")[0].strip() if row.get("source_urls") else ""
        src_url = fb_url or web_url
        src_type = "facebook" if fb_url else "website"
        evid = c(row.get("login_fb_evidence_summary","")) or c(row.get("evidence_summary",""))
        try:
            db.execute("""
                INSERT OR IGNORE INTO holdings
                (institution_id,species_id,holding_verdict,breeding_verdict,
                 last_offspring_year,confidence,source_type,source_url,evidence_summary)
                VALUES (?,?,?,?,?,?,?,?,?)
            """, (iid, kea_id, hv, bv, year, conf, src_type, src_url, evid))
            n += 1
        except Exception as e:
            print(f"  warn {iid}: {e}")

con.commit()
print(f"  {n} kea holdings from baseline")

# Notable expansion kea records (non-baseline, non-trivial verdict)
NOTABLE = {"confirmed_current","likely_current","probable_current",
           "historical_only","unresolved_after_review","unclear","no_public_evidence_after_review"}
print("Importing notable expansion kea records …")
n2 = 0
EXP_HOLD_MAP = {
    "confirmed_current":              "confirmed_current",
    "likely_current":                 "likely_current",
    "probable_current":               "likely_current",
    "historical_only":                "historical",
    "unresolved_after_review":        "unclear",
    "unclear":                        "unclear",
    "no_public_evidence_after_review":"unclear",
}
with open(MASTER, encoding="utf-8-sig") as f:
    for row in csv.DictReader(f):
        if c(row.get("baseline_locked","")) == "yes": continue
        verdict = c(row.get("final_kea_presence_verdict",""))
        if verdict not in NOTABLE: continue
        iid = c(row["record_id"])
        if not iid: continue
        hv   = EXP_HOLD_MAP.get(verdict,"unknown")
        bv   = BREED_MAP.get(c(row.get("breeding_verdict_baseline","")), "unknown")
        conf = c(row.get("final_confidence",""))
        year = c(row.get("last_offspring_year_baseline",""))
        src  = c(row.get("final_best_evidence_url",""))
        evid = c(row.get("final_evidence_summary",""))
        try:
            db.execute("""
                INSERT OR IGNORE INTO holdings
                (institution_id,species_id,holding_verdict,breeding_verdict,
                 last_offspring_year,confidence,source_url,evidence_summary)
                VALUES (?,?,?,?,?,?,?,?)
            """, (iid, kea_id, hv, bv, year, conf, src, evid))
            n2 += 1
        except Exception as e:
            print(f"  warn {iid}: {e}")

con.commit()
total_h = db.execute("SELECT COUNT(*) FROM holdings").fetchone()[0]
print(f"  {n2} notable expansion kea holdings")
print(f"\nTotal holdings: {total_h}")
print(f"Done -> {DB}")
con.close()
