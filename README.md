# PrintScript

Plak een Google Docs-link, krijg een drukklare PDF terug.

PrintScript is een PHP-webapp die je op een gewone webserver zet. Je geeft het
een Google Docs-URL (of je sleept een `.docx` naar het venster) en het levert
een PDF af die klaar is om te printen — met altijd dezelfde vier regels:

| | |
|---|---|
| **Opmerkingen** | worden volledig verwijderd, inclusief de comment-panelen |
| **Markeringen** | alle arceringen weg, **tekstkleur blijft staan** |
| **Afbeeldingen** | alleen op pagina 1; alles daarna wordt verwijderd |
| **Paginanummering** | blijft in de voettekst staan (en wordt toegevoegd als die ontbreekt) |

Daarnaast worden *suggesties* opgelost zoals je ze zou accepteren: ingevoegde
tekst blijft, geschrapte tekst verdwijnt.

---

## Installeren

### Op een gewone webserver (geen shell nodig)

1. Klik op **Code → Download ZIP** hierboven, of pak
   **printscript-php.zip** bij de [releases](../../releases/latest).
2. Pak het bestand uit.
3. Zet de inhoud in de webmap van je hosting (`public_html`, `www`, `httpdocs`).
4. Ga naar het adres in je browser. Klaar.

`vendor/` — de PDF-motor — staat in de repo, dus allebei de downloads zijn
compleet. Je hebt geen Composer, shell-toegang of Python nodig. Er is ook
niets te configureren: ontbreekt er iets op je server, dan zegt de pagina zelf
welke PHP-uitbreiding je hostingpartij moet aanzetten.

### Zelf aan de slag

```bash
git clone https://github.com/janpaulb/printscript.git
cd printscript
php -S localhost:8000     # draait meteen; vendor/ zit er al in
```

### Wat je server nodig heeft

| | |
|---|---|
| PHP | 8.1 of nieuwer |
| Verplicht | `zip`, `dom`, `mbstring` |
| Voor afbeeldingen | `gd` (ook voor bijgesneden afbeeldingen) |
| Voor Google Docs-links | `curl` |

Dat is de standaarduitrusting van vrijwel elke hosting. Zonder `curl` werkt het
uploaden van een `.docx` nog gewoon.

---

## Gebruik

1. Zet je Google Doc op **Delen → Iedereen met de link → Kijker**.
2. Plak de link. Meer niet: PrintScript ziet dat er een link staat en begint
   meteen. Het printvenster opent zodra de PDF klaar is, vanuit het
   voorbeeldvenster — je hoeft niets te downloaden of in een tabblad te openen.
3. **Zet het document daarna weer op privé.** De pagina zegt het er na het
   printen nog een keer bij, in het rood.

Liever zelf op een knop drukken? Zet onder **Opties** de schakelaar
*Printvenster meteen openen* uit; **Maak drukklare PDF** en **🖨 Printen**
blijven gewoon staan. Typen doet niets tot er echt een link staat, dus een
half ingevoerd adres begint nergens aan.

Ondersteunde linkvormen:

```
https://docs.google.com/document/d/<ID>/edit
https://docs.google.com/document/u/1/d/<ID>/edit
https://drive.google.com/file/d/<ID>/view
https://drive.google.com/open?id=<ID>
<ID>
```

Voor privédocumenten heb je een OAuth-token nodig: zet die in de omgeving als
`GOOGLE_ACCESS_TOKEN`, of stuur hem mee als `access_token` in de API-aanroep.

### Opties

| Optie | Standaard | Effect |
|---|---|---|
| Afbeeldingen alleen op pagina 1 | aan | uit = alle afbeeldingen behouden |
| Paginanummer toevoegen als het document er geen heeft | aan | uit = exact de voettekst van het document volgen |
| Geen paginanummer op pagina 1 | uit | handig als pagina 1 een omslag is |
| Printvenster meteen openen | aan | uit = pas printen als je op **🖨 Printen** klikt |

---

## API

Dezelfde ingang doet het werk:

```bash
# Google Docs-link
curl -X POST https://jouwsite.nl/printscript/ \
     -H 'Content-Type: application/json' \
     -d '{"url": "https://docs.google.com/document/d/<ID>/edit"}' \
     -o script.pdf

# Word-bestand
curl -X POST https://jouwsite.nl/printscript/ \
     -F file=@script.docx \
     -F 'options={"page_numbers_on_first_page": false}' \
     -o script.pdf
```

Bij succes komt er een PDF terug plus een header `X-PrintScript-Summary`: een
base64-JSON met het aantal pagina's, wat er verwijderd is en eventuele
waarschuwingen. Bij een fout komt er JSON terug (`{"error": "…"}`) met een
passende statuscode: 400 voor een onbruikbare link of een kapot bestand, 403
voor een document zonder leestoegang, 502 als Google zelf niet meewerkt.

---

## Hoe het werkt

```
Google Docs-URL ──► export?format=docx ──┐
                                         ├─► schoonmaken ─► lezen ─► opmaken ─► PDF
Geüploade .docx ─────────────────────────┘
```

| Bestand | Verantwoordelijkheid |
|---|---|
| `src/GoogleDocs.php` | link → document-id → `.docx` (+ de titel uit de response-header) |
| `src/Package.php` | het `.docx`-zip als onderdelen en relaties, volledig in het geheugen |
| `src/Clean.php` | opmerkingen en markeringen uit álle onderdelen, ook uit `styles.xml` |
| `src/Styles.php`, `src/Numbering.php` | `styles.xml` en `numbering.xml` platgeslagen |
| `src/HtmlRenderer.php` | WordprocessingML → HTML + CSS |
| `src/ImageCrop.php` | bijgesneden afbeeldingen echt bijsnijden |
| `src/Engine/MpdfEngine.php` | opmaak naar PDF, plus de pagina-1-regel voor afbeeldingen |
| `src/Pipeline.php` | de vier stappen aan elkaar |
| `index.php` | de webinterface en de API |

### De pagina-1-regel wordt op de échte opmaak toegepast

"Alle afbeeldingen na pagina 1" is een uitspraak over het *geprinte* document,
niet over de opmaakcode. Zoeken naar een pagina-einde in het `.docx` werkt
niet: in de meeste documenten staat er geen, en dan gebeurt er niets.

PrintScript maakt het document daarom één keer op, met een bladwijzer bij elke
afbeelding — mPDF onthoudt van bladwijzers op welke pagina ze staan. Alles
vanaf pagina 2 gaat eruit, en dan volgt een tweede opmaakronde. Die is veilig:
weghalen kan later materiaal alleen naar vóren trekken, dus wat op pagina 1
stond blijft daar en er kan niets bijkomen. Logo's in kop- en voetteksten
blijven buiten schot: alleen afbeeldingen in de lopende tekst tellen mee.

### Paginanummers worden geteld, niet overgeschreven

`PAGE`- en `NUMPAGES`-velden worden merktekens die de PDF-motor invult, zodat
er staat wat er werkelijk geprint wordt in plaats van het getal dat Word ooit
had onthouden. Begint het document de telling ergens anders — scripts zetten
de omslag vaak op nul, zodat de eerste bladzijde tekst pagina 1 is — dan wordt
dat overgenomen. En nummert het document zichzelf al, dan blijft een lege
voettekst op de omslag leeg: dat is een keuze, geen omissie.

### Witruimte is inhoud

De onzichtbare helft van een document bepaalt waar de pagina's eindigen, en
daar zitten de valkuilen. Vier regels die uit het meten van een echt script
naast de PDF van Google Docs zijn gekomen:

| | |
|---|---|
| Lege alinea | de run erin bepaalt de hoogte, maar alleen waar die iets zegt; voor de rest telt het alineamerk |
| Regelafstand | "1,15" is 1,15 maal de natuurlijke regelhoogte van het lettertype (≈1,15em), niet 1,15 maal het korps |
| Slotregelovergang | een alinea die op een `<br>` eindigt, eindigt op een lege regel — motoren gooien die weg |
| Afbeelding in de regel | krijgt de regelafstand van zijn alinea, net als een letter |

Onderkasting staat aan, want ook dat verschuift regeleindes: zonder kerning
breekt een regel een woord eerder af dan in het origineel.

### Lettertypen bepalen waar de regels afbreken

Dit is geen schoonheidskwestie. De breedte van elke letter bepaalt waar een
regel afbreekt, en dus waar een pagina eindigt. Zet je Arial-tekst in een ander
lettertype, dan loopt het hele document uit de pas met wat je in Google Docs
ziet.

PrintScript levert daarom de **Liberation**-familie mee (`fonts/`): metrisch
identiek aan Arial, Times New Roman en Courier New. Elke letter is even breed
als in het origineel, alleen de vorm verschilt minimaal — het is wat
LibreOffice ook doet als een document om een lettertype vraagt dat er niet is.
Andere namen (Calibri, Verdana, Georgia, Cambria) vallen terug op het
Liberation-lettertype dat er qua karakter het dichtst bij komt; daar zijn de
breedtes een benadering.

Voor een teken dat in geen van die lettertypen staat, valt mPDF terug op zijn
eigen reservelettertypen. Die lijst wordt eerst nagelopen: alleen wat er
werkelijk staat blijft erin. Anders gaat het pas mis op het moment dat iemand
een Chinees teken of een emoji gebruikt — en dan stopt de hele conversie met
een foutmelding over een lettertypebestand waar de gebruiker part noch deel aan
heeft. Kan een teken écht nergens uit, dan zegt de waarschuwing dát, in plaats
van dat er stilletjes een leeg vakje op papier komt.

### Bijgesneden afbeeldingen worden echt bijgesneden

Wie in Google Docs of Word een afbeelding bijsnijdt, gooit niets weg. Het hele
plaatje blijft in het bestand zitten; er staat alleen bij welk deel te zien is
(`a:srcRect`). Een opmaakmotor kent dat niet en tekent het hele plaatje in het
vakje dat voor het uitgesneden stuk bedoeld was — een bijgesneden omslagfoto
komt er dan platgeslagen uit. PrintScript snijdt hem daarom zelf bij, vóór de
motor hem te zien krijgt, mét behoud van doorzichtigheid.

### Kop- en voetteksten zijn echte kop- en voetteksten

Ze worden per sectie apart opgemaakt, met hun eigen opmaak, hun eigen logo's en
een aparte variant voor de titelpagina wanneer het document die heeft. Een
voettekst met tabs wordt een nette links/midden/rechts-verdeling.

---

## Tests

```bash
COMPOSER=composer-dev.json composer install   # eenmalig
composer test
```

Het testgereedschap staat bewust in een eigen map (`vendor-dev/`), los van de
`vendor/` die mee de server op gaat. Zo bevat die laatste precies wat er hoort
en niets meer — 29 MB in plaats van ruim 2 GB.

82 tests, ongeveer twee seconden. Ze bouwen `.docx`-pakketten met de hand
(`tests/DocxBuilder.php`) en controleren de **uitkomst in de PDF**
(`tests/PdfInspector.php`): welke pagina's er zijn, welke afbeeldingen
daadwerkelijk op welke pagina getekend worden, welke tekst er staat en welke er
níet staat.

Dat laatste vraagt om een PDF-lezer, want de tekst in een PDF bestaat uit
glyph-nummers van een subset-lettertype. `PdfInspector` pakt de paginastromen
uit en vertaalt ze terug via de ToUnicode-tabel die de PDF zelf meelevert. Zo
bewijzen de tests wat er op papier komt, niet welke functie is aangeroepen.

---

## Grenzen

| | |
|---|---|
| Maximale bestandsgrootte | 50 MB (en wat je hosting toestaat) |
| Invoer | `.docx` en Google Docs |
| Uitvoer | PDF |
| Zwevende afbeeldingen | staan op hun eigen plek en buiten de tekststroom, maar de tekst loopt er niet omheen |
| Verkeerd-om inspringen | een opsomming zet zijn bolletje binnen de kantlijn in plaats van ervoor; de PDF-motor kent geen negatieve inspringing |
| EMF-/WMF-afbeeldingen | worden overgeslagen (met een waarschuwing) |
| Tabstops | de standaardafstand; kop- en voetteksten met tabs worden wél links/midden/rechts |
| Titelpagina-instelling | wordt op de eerste sectie toegepast |
| Grafieken en SmartArt | worden overgeslagen (met een waarschuwing) |
| Lettertypen | Liberation (exact zo breed als Arial, Times New Roman en Courier New); andere lettertypen vallen daarop terug |
| Schriften | Latijn, Grieks, Cyrillisch en de gangbare symbolen. Chinees, Japans, Koreaans en emoji komen als leeg vakje op papier, met een waarschuwing erbij; Arabisch en Hebreeuws blijven ook leeg, maar dat merkt PrintScript zelf niet op |

Waarschuwingen komen in de webinterface onder het resultaat te staan en in de
`X-PrintScript-Summary`-header, zodat je weet wat er is overgeslagen in plaats
van dat het stilletjes verdwijnt.

---

## Uitrolpakket maken

```bash
./build-release.sh    # levert printscript-php.zip (13 MB)
```

Het script pakt gewoon in wat er in de repo staat; Composer komt er niet aan
te pas. In `vendor/` zitten alleen de DejaVu- en Free-lettertypen van mPDF en
geen testbestanden van de pakketten — dat scheelt ruim 200 MB, en de testsuite
draait tegen precies die uitgedunde set.
