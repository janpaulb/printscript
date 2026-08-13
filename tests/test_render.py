"""Rendering fidelity: the formatting that has to survive the trip to PDF."""

from __future__ import annotations

import fixtures as F
from conftest import all_text, page_texts, pdf_pages

from printscript.clean import clean
from printscript.docxhtml import render
from printscript.package import Package
from printscript.pipeline import ConversionOptions, convert_docx


def to_html(builder, body: str) -> str:
    package = Package(builder.build(body))
    clean(package)
    return render(package).to_html()


def to_pdf(builder, body: str, **options):
    return convert_docx(builder.build(body), ConversionOptions(**options))


# ── Character formatting ─────────────────────────────────────────────────────

def test_direct_character_formatting_becomes_css(builder):
    html = to_html(builder, F.paragraph(
        'Opvallend',
        run_properties='<w:b/><w:i/><w:u w:val="single"/><w:color w:val="1155CC"/>'
                       '<w:sz w:val="28"/>') + F.DEFAULT_SECTION)

    assert 'font-weight: bold' in html
    assert 'font-style: italic' in html
    assert 'text-decoration-line: underline' in html
    assert 'color: #1155CC' in html
    assert 'font-size: 14pt' in html


def test_explicit_off_switches_override_an_inherited_style(builder):
    builder.add_styles(
        '<w:styles ' + F.NS_DECL + '>'
        '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
        '<w:name w:val="Normal"/></w:style>'
        '<w:style w:type="character" w:styleId="Nadruk"><w:name w:val="Nadruk"/>'
        '<w:rPr><w:b/><w:u w:val="single"/></w:rPr></w:style></w:styles>')
    html = to_html(builder, F.paragraph(
        'Toch niet vet',
        run_properties='<w:rStyle w:val="Nadruk"/><w:b w:val="0"/>'
                       '<w:u w:val="none"/>') + F.DEFAULT_SECTION)

    assert 'font-weight: normal' in html
    assert 'text-decoration-line: none' in html


def test_heading_styles_become_heading_elements(builder):
    html = to_html(builder, F.paragraph('Bedrijf', style='Heading1') + F.DEFAULT_SECTION)

    assert '<h1 class="ps-p ps-s-Heading1">' in html


def test_superscript_and_small_caps(builder):
    html = to_html(builder, F.paragraph(
        'e', run_properties='<w:vertAlign w:val="superscript"/>') +
        F.paragraph('kop', run_properties='<w:smallCaps/>') + F.DEFAULT_SECTION)

    assert 'vertical-align: super' in html
    assert 'font-variant: small-caps' in html


# ── Paragraph formatting ─────────────────────────────────────────────────────

def test_alignment_indentation_and_spacing(builder):
    html = to_html(builder, F.paragraph(
        'Uitgevuld',
        properties='<w:jc w:val="both"/>'
                   '<w:ind w:left="720" w:right="360" w:firstLine="240"/>'
                   '<w:spacing w:before="120" w:after="240" w:line="360" '
                   'w:lineRule="auto"/>') + F.DEFAULT_SECTION)

    assert 'text-align: justify' in html
    assert 'margin-left: 36pt' in html
    assert 'margin-right: 18pt' in html
    assert 'text-indent: 12pt' in html
    assert 'margin-top: 6pt' in html
    assert 'margin-bottom: 12pt' in html
    assert 'line-height: 1.500' in html


def test_line_break_and_tab_render(builder):
    html = to_html(builder,
                   '<w:p><w:r><w:t>een</w:t><w:br/><w:t>twee</w:t>'
                   '<w:tab/><w:t>drie</w:t></w:r></w:p>' + F.DEFAULT_SECTION)

    assert '<br>' in html
    assert 'ps-tab' in html


# ── Page setup ───────────────────────────────────────────────────────────────

def test_page_size_and_margins_come_from_the_document(builder):
    body = F.paragraph('A5 liggend') + (
        '<w:sectPr><w:pgSz w:w="11906" w:h="8391" w:orient="landscape"/>'
        '<w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567"/>'
        '</w:sectPr>')

    page = pdf_pages(to_pdf(builder, body).pdf)[0]

    assert round(float(page.mediabox.width)) == 595    # 11906 twips
    assert round(float(page.mediabox.height)) == 420   # 8391 twips


def test_a_second_section_can_change_the_page_size(builder):
    portrait = ('<w:p><w:pPr><w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
                '<w:pgMar w:top="1417" w:right="1417" w:bottom="1417" '
                'w:left="1417"/></w:sectPr></w:pPr></w:p>')
    landscape = ('<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
                 '<w:pgMar w:top="1000" w:right="1000" w:bottom="1000" '
                 'w:left="1000"/></w:sectPr>')
    body = F.paragraph('Staand') + portrait + F.paragraph('Liggend') + landscape

    pages = pdf_pages(to_pdf(builder, body).pdf)

    assert len(pages) == 2
    assert float(pages[0].mediabox.width) < float(pages[0].mediabox.height)
    assert float(pages[1].mediabox.width) > float(pages[1].mediabox.height)


def test_page_break_before_starts_a_new_page(builder):
    body = (F.paragraph('Een') +
            F.paragraph('Twee', properties='<w:pageBreakBefore/>') +
            F.DEFAULT_SECTION)

    texts = page_texts(to_pdf(builder, body).pdf)

    assert len(texts) == 2
    assert 'Een' in texts[0] and 'Twee' in texts[1]


# ── Headers and footers ──────────────────────────────────────────────────────

def test_a_header_repeats_on_every_page(builder):
    header = builder.add_header('<w:p><w:r><w:t>Werktitel — vertrouwelijk</w:t></w:r></w:p>')
    footer = builder.add_footer(F.page_field_footer('Blz. '))
    body = (F.paragraph('Een') + F.page_break_paragraph() + F.paragraph('Twee') +
            F.section_with_footer(footer, header_rel=header))

    texts = page_texts(to_pdf(builder, body).pdf)

    assert all('Werktitel — vertrouwelijk' in text for text in texts)
    assert 'Blz. 1' in texts[0] and 'Blz. 2' in texts[1]


def test_tabbed_footer_keeps_its_three_columns(builder):
    footer = builder.add_footer(
        '<w:p><w:r><w:t>Scenario</w:t></w:r><w:r><w:tab/></w:r>'
        '<w:r><w:t>versie 3</w:t></w:r><w:r><w:tab/></w:r>'
        '<w:fldSimple w:instr=" PAGE "><w:r><w:t>1</w:t></w:r></w:fldSimple></w:p>')
    body = F.paragraph('Inhoud') + F.section_with_footer(footer)

    text = page_texts(to_pdf(builder, body).pdf)[0]

    assert 'Scenario' in text and 'versie 3' in text and '1' in text


def test_title_page_suppresses_the_running_header(builder):
    header = builder.add_header('<w:p><w:r><w:t>KOPTEKST</w:t></w:r></w:p>')
    footer = builder.add_footer(F.page_field_footer())
    body = (F.paragraph('Omslag') + F.page_break_paragraph() + F.paragraph('Body') +
            F.section_with_footer(footer, header_rel=header, title_page=True))

    texts = page_texts(to_pdf(builder, body).pdf)

    assert 'KOPTEKST' not in texts[0]
    assert 'KOPTEKST' in texts[1]


# ── Lists and tables ─────────────────────────────────────────────────────────

NUMBERING = (
    '<w:abstractNum w:abstractNumId="0">'
    '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/>'
    '<w:lvlText w:val="%1."/>'
    '<w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>'
    '<w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="lowerLetter"/>'
    '<w:lvlText w:val="%1.%2."/>'
    '<w:pPr><w:ind w:left="1440" w:hanging="360"/></w:pPr></w:lvl>'
    '</w:abstractNum>'
    '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
    '<w:abstractNum w:abstractNumId="1">'
    '<w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/><w:lvlText w:val=""/>'
    '<w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>'
    '</w:abstractNum>'
    '<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>')


def list_item(text: str, level: int = 0, num_id: str = '1') -> str:
    return F.paragraph(text, properties='<w:numPr><w:ilvl w:val="%d"/>'
                                        '<w:numId w:val="%s"/></w:numPr>'
                                        % (level, num_id))


def test_multilevel_numbering_counts_like_word(builder):
    builder.add_numbering(NUMBERING)
    body = (list_item('Een') + list_item('Twee') + list_item('Twee-a', 1) +
            list_item('Twee-b', 1) + list_item('Drie') + F.DEFAULT_SECTION)

    text = all_text(to_pdf(builder, body).pdf)

    assert '1. Een' in text
    assert '2. Twee' in text
    assert '2.a. Twee-a' in text
    assert '2.b. Twee-b' in text
    assert '3. Drie' in text


def test_symbol_bullets_are_mapped_to_real_glyphs(builder):
    builder.add_numbering(NUMBERING)
    body = list_item('Opsomming', 0, num_id='2') + F.DEFAULT_SECTION

    text = all_text(to_pdf(builder, body).pdf)

    assert '•' in text
    assert '' not in text


def test_table_cells_merges_and_borders(builder):
    table = (
        '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>'
        '<w:top w:val="single" w:sz="8" w:color="000000"/>'
        '<w:left w:val="single" w:sz="8" w:color="000000"/>'
        '<w:bottom w:val="single" w:sz="8" w:color="000000"/>'
        '<w:right w:val="single" w:sz="8" w:color="000000"/>'
        '<w:insideH w:val="single" w:sz="4" w:color="808080"/>'
        '<w:insideV w:val="single" w:sz="4" w:color="808080"/>'
        '</w:tblBorders></w:tblPr>'
        '<w:tblGrid><w:gridCol w:w="4000"/><w:gridCol w:w="2000"/>'
        '<w:gridCol w:w="2000"/></w:tblGrid>'
        '<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr>'
        + F.paragraph('Scène 1') + '</w:tc>'
        '<w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr>'
        + F.paragraph('Twee kolommen breed') + '</w:tc></w:tr>'
        '<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr>' + F.paragraph('') + '</w:tc>'
        '<w:tc>' + F.paragraph('Links') + '</w:tc>'
        '<w:tc>' + F.paragraph('Rechts') + '</w:tc></w:tr></w:tbl>')

    html = to_html(builder, table + F.DEFAULT_SECTION)
    text = all_text(to_pdf(builder, table + F.DEFAULT_SECTION).pdf)

    assert 'rowspan="2"' in html
    assert 'colspan="2"' in html
    assert 'border-top: 1pt solid #000000' in html
    for expected in ('Scène 1', 'Twee kolommen breed', 'Links', 'Rechts'):
        assert expected in text


# ── Revisions, fields, hyperlinks ────────────────────────────────────────────

def test_tracked_changes_are_resolved(builder):
    body = ('<w:p>'
            '<w:ins w:id="1" w:author="A"><w:r><w:t xml:space="preserve">'
            'toegevoegd </w:t></w:r></w:ins>'
            '<w:del w:id="2" w:author="A"><w:r><w:delText>geschrapt</w:delText>'
            '</w:r></w:del>'
            '<w:r><w:t>slot</w:t></w:r></w:p>' + F.DEFAULT_SECTION)

    text = all_text(to_pdf(builder, body).pdf)

    assert 'toegevoegd' in text
    assert 'slot' in text
    assert 'geschrapt' not in text


def test_an_unknown_field_keeps_its_cached_result(builder):
    body = ('<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            '<w:r><w:instrText> DATE \\@ "d-M-yyyy" </w:instrText></w:r>'
            '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            '<w:r><w:t>13-8-2026</w:t></w:r>'
            '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>' + F.DEFAULT_SECTION)

    assert '13-8-2026' in all_text(to_pdf(builder, body).pdf)


def test_hidden_text_is_not_printed(builder):
    body = (F.paragraph('zichtbaar') +
            F.paragraph('onzichtbaar', run_properties='<w:vanish/>') +
            F.DEFAULT_SECTION)

    text = all_text(to_pdf(builder, body).pdf)

    assert 'zichtbaar' in text
    assert 'onzichtbaar' not in text


# ── Robustness ───────────────────────────────────────────────────────────────

def test_an_unsupported_image_format_is_reported_not_fatal(builder):
    emf = builder.add_image(b'\x01\x00\x00\x00 not really an EMF', extension='emf')
    body = F.paragraph('Tekst') + '<w:p>' + F.image_run(emf) + '</w:p>' + F.DEFAULT_SECTION

    result = to_pdf(builder, body)

    assert result.page_count == 1
    assert any('EMF' in warning for warning in result.warnings)


def test_an_empty_document_still_produces_a_pdf(builder):
    result = to_pdf(builder, F.DEFAULT_SECTION)

    assert result.page_count == 1
    assert result.pdf.startswith(b'%PDF')


def test_a_document_without_a_section_uses_a4(builder):
    page = pdf_pages(to_pdf(builder, F.paragraph('Zonder sectie')).pdf)[0]

    assert round(float(page.mediabox.width)) == 595
    assert round(float(page.mediabox.height)) == 842
