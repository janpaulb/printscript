# PrintScript

Plak een Google Docs-link, krijg een drukklare PDF terug.

PrintScript is een webapp. Je geeft het een Google Docs-URL (of je sleept een
`.docx` naar het venster) en het levert een PDF af die klaar is om te printen —
met altijd dezelfde vier regels:

| | |
|---|---|
| **Opmerkingen** | worden volledig verwijderd, inclusief de comment-panelen |
| **Markeringen** | alle arceringen weg, **tekstkleur blijft staan** |
| **Afbeeldingen** | alleen op pagina 1; alles daarna wordt verwijderd |
| **Paginanummering** | blijft in de voettekst staan (en wordt toegevoegd als die ontbreekt) |

Daarnaast worden *suggesties* opgelost zoals je ze zou accepteren: ingevoegde
tekst blijft, geschrapte tekst verdwijnt.

---

## Snel starten

### Docker (aanbevolen — je hoeft niets te installeren)

```bash
docker run -p 5000:5000 ghcr.io/janpaulb/printscript:latest
```

of vanuit de repository:

```bash
git clone https://github.com/janpaulb/printscript.git
cd printscript
docker compose up
```

Open [http://localhost:5000](http://localhost:5000).

### Zonder Docker

Nodig: Python 3.11+ en de Pango-bibliotheken die WeasyPrint gebruikt
(`brew install pango libffi` op macOS, `apt-get install libpango-1.0-0
libpangoft2-1.0-0` op Debian/Ubuntu).

```bash
./run.sh
```

Het script maakt een virtuele omgeving, installeert de packages, controleert of
WeasyPrint werkt en start de server op poort 5000.

---

## Gebruik

1. Zet je Google Doc op **Delen → Iedereen met de link → Kijker**.
2. Plak de link in PrintScript en klik op **Maak drukklare PDF**.
3. Bekijk het resultaat in het voorbeeldvenster, download het of open het om te
   printen.

Ondersteunde linkvormen:

```
https://docs.google.com/document/d/<ID>/edit
https://docs.google.com/document/u/1/d/<ID>/edit
https://drive.google.com/file/d/<ID>/view
https://drive.google.com/open?id=<ID>
<ID>
```

Voor privédocumenten heb je een OAuth-token nodig; zet die in de omgeving als
`GOOGLE_ACCESS_TOKEN` of stuur hem mee als `access_token` in de API-aanroep.

### Opties

| Optie | Standaard | Effect |
|---|---|---|
| Afbeeldingen alleen op pagina 1 | aan | zet uit om alle afbeeldingen te behouden |
| Paginanummer toevoegen als het document er geen heeft | aan | zet uit om exact de voettekst van het document te volgen |
| Geen paginanummer op pagina 1 | uit | handig als pagina 1 een omslag is |

---

## API

```bash
# Google Docs-link
curl -X POST http://localhost:5000/api/convert \
     -H 'Content-Type: application/json' \
     -d '{"url": "https://docs.google.com/document/d/<ID>/edit"}' \
     -o script.pdf

# Word-bestand
curl -X POST http://localhost:5000/api/convert \
     -F file=@script.docx \
     -F 'options={"page_numbers_on_first_page": false}' \
     -o script.pdf
```

Bij succes komt er een PDF terug plus een header `X-PrintScript-Summary`: een
base64-JSON met het aantal pagina's, wat er verwijderd is en eventuele
waarschuwingen. Bij een fout komt er JSON terug (`{"error": "…"}`) met een
passende statuscode: 400 voor een onbruikbare link of een kapot bestand, 403
voor een document zonder leestoegang, 502 als Google zelf niet meewerkt.

`GET /healthz` geeft `{"status": "ok"}` voor je loadbalancer.

---

## Hoe het werkt

```
Google Docs-URL ──► export?format=docx ──┐
                                         ├─► clean ─► render ─► layout ─► PDF
Geüploade .docx ─────────────────────────┘
```

| Module | Verantwoordelijkheid |
|---|---|
| `printscript/gdocs.py` | link → document-id → `.docx`-bytes (+ de titel uit de response-header) |
| `printscript/package.py` | het `.docx`-zip als parts en relaties, volledig in het geheugen |
| `printscript/clean.py` | opmerkingen en markeringen uit álle parts, ook uit `styles.xml` |
| `printscript/styles.py` | `styles.xml` en `numbering.xml` platgeslagen tot bruikbare tabellen |
| `printscript/docxhtml.py` | WordprocessingML → HTML + CSS |
| `printscript/pdf.py` | opmaak naar PDF, plus de pagina-1-regel voor afbeeldingen |
| `printscript/pipeline.py` | de vier stappen aan elkaar geknoopt |
| `app.py` | Flask-routes en de webinterface |

### Waarom dit werkt waar de vorige versie faalde

**De pagina-1-regel wordt op de échte opmaak toegepast.** "Alle afbeeldingen na
pagina 1" is een uitspraak over het *geprinte* document, niet over de opmaakcode.
De oude versie zocht naar een expliciete pagina-einde-tag in het `.docx` en
gokte de rest; stond er geen pagina-einde in — het normale geval — dan gebeurde
er niets. PrintScript maakt de opmaak nu één keer, vraagt WeasyPrint op welke
pagina elke afbeelding is beland, gooit alles vanaf pagina 2 weg en maakt de
opmaak opnieuw. Die tweede ronde is veilig: weghalen kan tekst alleen naar
vóren trekken, dus wat op pagina 1 stond blijft daar en er kan niets nieuws
bijkomen.

**Paginanummers worden geteld, niet overgeschreven.** `PAGE`- en
`NUMPAGES`-velden worden CSS-tellers (`counter(page)`), zodat er staat wat er
werkelijk geprint wordt in plaats van het getal dat Word ooit had onthouden.

**Kop- en voetteksten zijn echte kop- en voetteksten.** Ze worden CSS *running
elements* in de marges van `@page`, met hun eigen opmaak, hun eigen logo's en
een aparte variant voor de titelpagina wanneer het document die heeft.

**Geen extern conversieproces.** De vorige versies riepen LibreOffice aan — en
verdronken in headless-modi, VCL-plug-ins, `DYLD_*`-variabelen en
codesign-problemen op macOS. Er is nu geen los proces, geen office-suite en
geen display: `lxml` leest het document, PrintScript rendert het zelf en
WeasyPrint maakt er een PDF van.

**Niets gaat het netwerk op.** De PDF-renderer krijgt een URL-fetcher die
alleen `data:` toestaat, dus een document kan de server nooit een externe URL
laten ophalen. Alle afbeeldingen zitten al als `data:`-URI in de HTML.

---

## Tests

```bash
pip install -r requirements-dev.txt
python -m pytest
```

93 tests, ongeveer twee seconden. Ze bouwen `.docx`-pakketten met de hand
(`tests/fixtures.py`) en controleren de **uitkomst in de PDF** — welke pagina's
er zijn, welke afbeeldingen daadwerkelijk op welke pagina getekend worden, welke
tekst er staat en welke er níet staat. De Docker-build draait dezelfde suite,
dus een image die geen document kan omzetten wordt niet gepubliceerd.

---

## Grenzen

| | |
|---|---|
| Maximale bestandsgrootte | 50 MB |
| Invoer | `.docx` en Google Docs |
| Uitvoer | PDF |
| Zwevende afbeeldingen | worden in de tekstregel geplaatst, niet omlopend |
| EMF-/WMF-afbeeldingen | worden overgeslagen (met een waarschuwing) — dat formaat kan niet in een PDF |
| Tabstops | de standaardafstand; kop- en voetteksten met tabs worden wél als links/midden/rechts opgemaakt |
| Titelpagina-instelling | wordt op de eerste sectie toegepast |
| Grafieken en SmartArt | worden overgeslagen (met een waarschuwing) |

Waarschuwingen komen in de webinterface onder het resultaat te staan en in de
`X-PrintScript-Summary`-header, zodat je weet wat er is overgeslagen in plaats
van dat het stilletjes verdwijnt.
