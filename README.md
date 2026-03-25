# PrintScript

Converteer Word-documenten (.docx) naar drukklare PDF's — via de browser of als native macOS-app.

**Wat PrintScript doet:**

- Verwijdert alle **opmerkingen** (comments)
- Verwijdert alle **markeringen** (highlights/arceringen) — tekst­kleur blijft intact
- Verwijdert alle **afbeeldingen na pagina 1** — de omslagpagina blijft ongewijzigd
- Behoudt **paginanummering** in de voettekst
- Accepteert ook een **Google Docs-link** — PrintScript downloadt het document zelf

---

## Downloaden (macOS — geen Terminal nodig)

1. Ga naar **[Releases](../../releases/latest)**
2. Download de DMG voor jouw Mac:
   - **Apple Silicon (M1/M2/M3/M4)** → `PrintScript_arm64.dmg`
   - **Intel** → `PrintScript_x86_64.dmg`
3. Open de DMG en sleep **PrintScript** naar **Applications**
4. Dubbelklik op PrintScript in je Applications-map

> Eerste keer openen: rechtsklik op het app-icoontje → **"Open"** als macOS een beveiligingswaarschuwing geeft (Gatekeeper).

---

## Inhoud

- [Downloaden (macOS)](#downloaden-macos--geen-terminal-nodig)
- [Snel starten (webserver)](#snel-starten-webserver)
- [Native macOS-app bouwen](#native-macos-app-bouwen)
- [Google Docs-ondersteuning](#google-docs-ondersteuning)
- [Projectstructuur](#projectstructuur)
- [Hoe het werkt](#hoe-het-werkt)
- [Limieten en beperkingen](#limieten-en-beperkingen)

---

## Snel starten (webserver)

### Vereisten

- Python 3.11 of hoger
- LibreOffice (inclusief de headless backend)

```bash
# Debian / Ubuntu (server / VPS — zonder GUI)
sudo apt-get install libreoffice-writer libreoffice-headless

# Debian / Ubuntu (als bovenstaande niet werkt: virtueel X11-scherm)
sudo apt-get install xvfb

# macOS (Homebrew)
brew install --cask libreoffice
```

> **Fout "no suitable windowing system found"?**
> LibreOffice mist de headless-renderer. Voer het volgende uit:
> `sudo apt-get install libreoffice-headless`
> Als dat niet helpt: `sudo apt-get install xvfb` — PrintScript valt daar automatisch op terug.

### Installeren en draaien

```bash
# 1. Clone de repository
git clone https://github.com/janpaulb/printscript.git
cd printscript

# 2. Installeer Python-packages
pip install -r requirements.txt

# 3. Start de webserver
python app.py
```

Open je browser op [http://localhost:5000](http://localhost:5000).

Sleep een `.docx`-bestand op de uploadzone of plak een Google Docs-URL. De PDF wordt automatisch gedownload.

---

## Native macOS-app bouwen

`build_mac.sh` bouwt een volledig **zelfstandige** `.app` die LibreOffice intern meebundelt. Je hoeft verder niets te installeren.

### Vereisten

- macOS (Intel of Apple Silicon)
- Python 3.11+
- Internetverbinding (~400 MB voor de LibreOffice-download)

### Bouwen

```bash
chmod +x build_mac.sh
./build_mac.sh
```

Het script:
1. Detecteert je architectuur (arm64 / x86_64)
2. Downloadt de nieuwste stabiele LibreOffice van documentfoundation.org
3. Bundelt LibreOffice in de app
4. Bouwt `dist/PrintScript.app` via PyInstaller

```bash
# Testen
open dist/PrintScript.app

# Installeren via DMG (sleep-naar-Applications venster)
open dist/PrintScript_arm64.dmg   # Apple Silicon
open dist/PrintScript_x86_64.dmg  # Intel
```

**Appgrootte:** ±500–600 MB (LibreOffice is groot).

### Automatisch bouwen via GitHub Actions

Push een versie-tag en GitHub bouwt de DMG automatisch voor beide architecturen:

```bash
git tag v1.0.0
git push --tags
```

De DMGs verschijnen onder **Releases** zodra de build klaar is (~10–15 minuten). Teamleden en eindgebruikers downloaden gewoon de DMG — geen Terminal, geen Python, geen LibreOffice installeren.

### Automatische LibreOffice-updates

De macOS-app controleert wekelijks op een nieuwe LibreOffice-versie en downloadt die op de achtergrond. Een banner in de UI meldt wanneer een update gereed is. De update wordt bij de volgende herstart toegepast.

---

## Google Docs-ondersteuning

Ondersteunde URL-patronen:

```
https://docs.google.com/document/d/<ID>/edit
https://docs.google.com/document/d/<ID>/edit?usp=sharing
https://drive.google.com/file/d/<ID>/view
https://drive.google.com/open?id=<ID>
```

Het document moet ingesteld zijn op **"Iedereen met de link kan bekijken"**. Private documenten werken niet zonder OAuth-token.

---

## Projectstructuur

```
printscript/
├── app.py              # Flask-webserver (routes, file upload, Google Docs)
├── processor.py        # Documentpijplijn: comments → highlights → afbeeldingen → PDF
├── gdocs.py            # Google Docs downloader
├── updater.py          # LibreOffice auto-updater (macOS)
├── main.py             # Native macOS-app entry point (pywebview + Flask)
├── templates/
│   └── index.html      # Webinterface (twee tabbladen: upload / URL)
├── static/
│   ├── app.js          # Frontend-logica
│   └── style.css       # Dark-theme styling
├── test_processor.py   # Smoke tests voor de documentpijplijn
├── PrintScript.spec    # PyInstaller spec voor de macOS-app
├── build_mac.sh        # Bouwscript voor de macOS-app
└── requirements.txt    # Python-packages
```

---

## Hoe het werkt

### Documentpijplijn (`processor.py`)

```
.docx-invoer
    │
    ├─ remove_comments()             Verwijdert commentRangeStart/End,
    │                                commentReference-runs en de comments-
    │                                relatie uit het OOXML-pakket.
    │
    ├─ remove_highlighting()         Verwijdert w:highlight en w:shd op
    │                                zowel run-niveau als paragraaf-niveau.
    │                                w:color (tekstkleur) blijft intact.
    │
    ├─ remove_images_after_page_one  Detecteert de eerste pagina-einde
    │                                (expliciete w:br of sectPr).
    │                                Verwijdert w:drawing, w:pict,
    │                                mc:AlternateContent en VML-vormen
    │                                op alle volgende pagina's.
    │
    └─ convert_to_pdf()              LibreOffice headless met een uniek
                                     gebruikersprofiel per conversie
                                     (gelijktijdige conversies zijn veilig).
```

### Pagina-1-detectie

PrintScript zoekt naar het eerste paginaeinde in het document:
- een expliciete `<w:br w:type="page"/>` binnen een run, of
- een `<w:sectPr>` met type `nextPage`, `evenPage` of `oddPage` in een paragraaf.

De body-level `<w:sectPr>` (die de laatste sectie beschrijft) wordt bewust overgeslagen.

### Gelijktijdigheid

Elke conversie krijgt een eigen LibreOffice-gebruikersprofiel (`-env:UserInstallation=file://…`) om conflicten te voorkomen bij meerdere gelijktijdige aanvragen.

---

## Limieten en beperkingen

| Punt | Waarde |
|---|---|
| Max. bestandsgrootte upload | 50 MB |
| Max. Google Docs download | 50 MB |
| LibreOffice-conversie timeout | 120 seconden |
| Google Docs verbindingstimeout | 10 seconden |
| Google Docs leestimeout | 300 seconden |
| Ondersteunde invoerformaten | `.docx` |
| Uitvoerformaat | PDF |

---

## Tests draaien

```bash
python test_processor.py
```

Verwachte uitvoer:

```
  [OK] Run highlights removed: 0 remain
  [OK] Paragraph shadings removed: 0 remain
  [OK] Drawings after page 1 removed: 1 remains (page 1)
  [OK] Comment markers removed: 0 remain

All tests passed.
```
