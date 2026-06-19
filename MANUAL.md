# ZooTrack – manuál pro vkládání dat

Tento dokument popisuje strukturu databáze a způsob vkládání dat – jak ručně přes API, tak automatizovaně (např. agentem AI).

---

## Přehled struktury

Databáze `zoo_db.sqlite` obsahuje tři tabulky:

```
institutions  →  hlavní seznam 2 240 evropských institucí (zoo, ptáčnice, záchranné stanice…)
species       →  sledované druhy živočichů (např. Nestor notabilis)
holdings      →  záznamy: kdo (instituce) drží jaké (druh) zvíře, s jakým výsledkem
```

Vztah: každý záznam v `holdings` propojuje jednu instituci s jedním druhem. Kombinace `(institution_id, species_id)` je unikátní — každý druh v každé instituci má vždy max. jeden řádek.

---

## Tabulka: institutions

Naplněna jednorázově z CSV, ručně se nemění (jen pole `notes` a `updated_at`).

| Sloupec | Typ | Popis |
|---|---|---|
| `id` | TEXT PK | Unikátní ID, formát `B02-0057` (kód země + pořadové číslo) |
| `country` | TEXT | Stát (anglicky, např. `Czech Republic`) |
| `subdivision` | TEXT | Kraj / spolková země |
| `city` | TEXT | Město |
| `institution` | TEXT | Název instituce |
| `institution_aliases` | TEXT | Alternativní názvy (oddělené `;`) |
| `institution_type` | TEXT | Typ: `zoo`, `wildlife park`, `bird park`, `rescue centre`, … |
| `website` | TEXT | URL webu |
| `eaza_status` | TEXT | `EAZA - Full member` / `EAZA - Candidate for Membership` / `non-EAZA` |
| `other_memberships` | TEXT | Ostatní asociace (WAZA, IZE, …) |
| `kea_verdict` | TEXT | Výsledek kea průzkumu (pouze pro kea; přednaplněno) |
| `kea_confidence` | TEXT | Spolehlivost kea výsledku |
| `kea_evidence` | TEXT | Shrnutí kea evidence |
| `notes` | TEXT | Volné poznámky (editovatelné přes UI) |
| `created_at` | TEXT | datetime('now') |
| `updated_at` | TEXT | automaticky při UPDATE |

---

## Tabulka: species

Předdefinovaných 35 druhů, lze přidávat přes UI nebo API.

| Sloupec | Typ | Popis |
|---|---|---|
| `id` | INTEGER PK AUTOINCREMENT | Číselné ID |
| `scientific_name` | TEXT UNIQUE | Vědecký název (povinný, unikátní) |
| `common_name_cs` | TEXT | Český název |
| `common_name_en` | TEXT | Anglický název |
| `common_name_de` | TEXT | Německý název |
| `taxon_class` | TEXT | `Aves`, `Mammalia`, `Reptilia`, … |
| `taxon_order` | TEXT | Řád |
| `taxon_family` | TEXT | Čeleď |
| `iucn_status` | TEXT | `EX`, `EW`, `CR`, `EN`, `VU`, `NT`, `LC` |
| `cites_appendix` | TEXT | `I`, `II`, `III` nebo prázdné |
| `eep` | INTEGER | `1` = EEP/ESB spravovaný druh, `0` = ne |
| `notes` | TEXT | Poznámky |
| `created_at` | TEXT | datetime('now') |

---

## Tabulka: holdings

Hlavní datová tabulka – jeden řádek = jeden druh v jedné instituci.

| Sloupec | Typ | Popis |
|---|---|---|
| `id` | INTEGER PK AUTOINCREMENT | ID záznamu |
| `institution_id` | TEXT FK → institutions.id | např. `B02-0057` |
| `species_id` | INTEGER FK → species.id | ID druhu |
| `holding_verdict` | TEXT | Přítomnost zvířete – viz hodnoty níže |
| `sex_ratio` | TEXT | Pohlaví/počty ve formátu `samci.samice.neurčení` (např. `2.3.0`) |
| `count_note` | TEXT | Slovní komentář k počtu (např. `"min. 2 jedinci"`) |
| `breeding_verdict` | TEXT | Chov – viz hodnoty níže |
| `last_offspring_year` | TEXT | Rok posledního odchovu (např. `"2023"`) |
| `confidence` | TEXT | Spolehlivost záznamu: `high`, `medium`, `low` |
| `source_type` | TEXT | Typ zdroje – viz hodnoty níže |
| `source_url` | TEXT | URL zdroje (přímý odkaz na stránku/post) |
| `evidence_date` | TEXT | Datum zjištění ve formátu `YYYY-MM-DD` |
| `evidence_summary` | TEXT | Stručný popis co bylo zjištěno |
| `notes` | TEXT | Interní poznámky |
| `created_at` | TEXT | datetime('now') |
| `updated_at` | TEXT | aktualizovat při každém UPDATE |

### Povolené hodnoty – holding_verdict (přítomnost)

| Hodnota | Význam |
|---|---|
| `confirmed_current` | Zvíře potvrzeně přítomno |
| `likely_current` | Pravděpodobně přítomno |
| `historical` | Historicky chováno, aktuálně neznámo/nepřítomno |
| `not_current` | Potvrzeně nepřítomno |
| `unclear` | Nejasné, rozporné informace |
| `unknown` | Žádná dostupná informace |

### Povolené hodnoty – breeding_verdict (chov)

| Hodnota | Význam |
|---|---|
| `confirmed` | Potvrzen odchov |
| `likely` | Pravděpodobný odchov (nepřímé signály) |
| `no_evidence` | Bez evidence o chovu |
| `historical` | Chov pouze historicky |
| `unknown` | Neznámo |

### Povolené hodnoty – confidence (spolehlivost)

| Hodnota | Význam |
|---|---|
| `high` | Vysoká – primární zdroj, přímé potvrzení |
| `medium` | Střední – sekundární zdroj nebo starší data |
| `low` | Nízká – nepřímý zdroj, pochybná data |

### Povolené hodnoty – source_type (typ zdroje)

| Hodnota | Zdroj |
|---|---|
| `website` | Webová stránka instituce |
| `facebook` | Facebook profil / příspěvek |
| `instagram` | Instagram |
| `zims` | ZIMS (Species360) |
| `zootierliste` | Zootierliste.de |
| `zoochat` | ZooChat.com |
| `visitor_report` | Zpráva návštěvníka |
| `direct_contact` | Přímý kontakt s institucí |
| `other` | Jiný zdroj |

---

## Vkládání dat přes REST API

Všechny operace jdou přes `api.php`. Základní URL: `https://vetapp.zootabor.eu/zoos/api.php`

### Přidat nebo aktualizovat záznam o druhu v instituci

```
POST api.php?action=save_holding
Content-Type: application/json
```

**Tělo požadavku:**
```json
{
  "institution_id": "B02-0057",
  "species_id": 1,
  "holding_verdict": "confirmed_current",
  "sex_ratio": "2.3.0",
  "count_note": "minimálně 5 jedinců",
  "breeding_verdict": "confirmed",
  "last_offspring_year": "2024",
  "confidence": "high",
  "source_type": "facebook",
  "source_url": "https://www.facebook.com/zoo/posts/12345",
  "evidence_date": "2025-03-15",
  "evidence_summary": "Na FB příspěvku zoo potvrzeno 5 kea, foto mláďat z roku 2024.",
  "notes": ""
}
```

**Chování:**
- Pokud `id` není uvedeno (nebo je 0), systém nejprve zkontroluje, zda záznam pro tuto kombinaci `(institution_id, species_id)` už existuje.
- Pokud existuje → provede UPDATE.
- Pokud neexistuje → provede INSERT.
- Vrací `{"ok": true, "id": 42}`.

**Aktualizace existujícího záznamu (explicitní):**
```json
{
  "id": 42,
  "institution_id": "B02-0057",
  "species_id": 1,
  "holding_verdict": "likely_current",
  ...
}
```

### Přidat nový druh

```
POST api.php?action=save_species
Content-Type: application/json
```

```json
{
  "scientific_name": "Strigops habroptilus",
  "common_name_cs": "Kakapo",
  "common_name_en": "Kakapo",
  "common_name_de": "Kakapo",
  "taxon_class": "Aves",
  "taxon_order": "Psittaciformes",
  "taxon_family": "Strigopidae",
  "iucn_status": "CR",
  "cites_appendix": "I",
  "eep": 1,
  "notes": ""
}
```

### Smazat záznam

```
POST api.php?action=delete_holding
Content-Type: application/json

{"id": 42}
```

### Načíst seznam institucí (filtrování)

```
GET api.php?action=institutions&country=Czech+Republic&eaza=EAZA&species_id=1&breeding=confirmed&page=1&per=50
```

Parametry:
- `q` – fulltextové hledání (název, město, ID)
- `country` – stát
- `eaza` – `EAZA` nebo `non`
- `species_id` – ID druhu
- `breeding` – filtr chovu (jen pokud je zadáno `species_id`)
- `page`, `per` – stránkování (default 1, 50)

### Načíst detail instituce

```
GET api.php?action=institution&id=B02-0057
```

Vrátí všechna data instituce včetně pole `holdings` se záznamy o všech druzích.

### Načíst seznam druhů

```
GET api.php?action=species
```

---

## Hromadné vkládání dat (Python příklad)

```python
import requests, json

BASE = "https://vetapp.zootabor.eu/zoos/api.php"

records = [
    {
        "institution_id": "B02-0057",
        "species_id": 1,
        "holding_verdict": "confirmed_current",
        "breeding_verdict": "confirmed",
        "last_offspring_year": "2023",
        "confidence": "high",
        "source_type": "website",
        "source_url": "https://zoo-example.com/animals/kea",
        "evidence_date": "2025-01-10",
        "evidence_summary": "Kea uvedena na webu zoo v sekci ptáci.",
    },
    # ... další záznamy
]

for rec in records:
    r = requests.post(f"{BASE}?action=save_holding",
                      headers={"Content-Type": "application/json"},
                      data=json.dumps(rec))
    print(rec["institution_id"], r.json())
```

---

## Jak zjistit ID instituce

ID jsou ve formátu `B02-0057` (kód země B + číslo státu + pořadové číslo).

```
GET api.php?action=institutions&q=Zoo+Praha&per=10
```

Nebo vyhledat přímo v databázi:

```python
import sqlite3
con = sqlite3.connect("zoo_db.sqlite")
rows = con.execute("SELECT id, institution, city FROM institutions WHERE institution LIKE '%Praha%'").fetchall()
print(rows)
```

---

## Schéma (zkrácené SQL)

```sql
CREATE TABLE institutions (
    id TEXT PRIMARY KEY,
    country TEXT, subdivision TEXT, city TEXT,
    institution TEXT, institution_aliases TEXT, institution_type TEXT,
    website TEXT, eaza_status TEXT, other_memberships TEXT,
    kea_verdict TEXT, kea_confidence TEXT, kea_evidence TEXT,
    notes TEXT, created_at TEXT, updated_at TEXT
);

CREATE TABLE species (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scientific_name TEXT UNIQUE,
    common_name_cs TEXT, common_name_en TEXT, common_name_de TEXT,
    taxon_class TEXT, taxon_order TEXT, taxon_family TEXT,
    iucn_status TEXT, cites_appendix TEXT, eep INTEGER DEFAULT 0,
    notes TEXT, created_at TEXT
);

CREATE TABLE holdings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    institution_id TEXT REFERENCES institutions(id),
    species_id INTEGER REFERENCES species(id),
    holding_verdict TEXT, sex_ratio TEXT, count_note TEXT,
    breeding_verdict TEXT, last_offspring_year TEXT,
    confidence TEXT, source_type TEXT, source_url TEXT,
    evidence_date TEXT, evidence_summary TEXT, notes TEXT,
    created_at TEXT, updated_at TEXT,
    UNIQUE(institution_id, species_id)
);
```
