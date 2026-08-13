"""
The four printing rules, asserted end to end against the generated PDF.

These are the tests that matter: they do not check that some function was
called, they check what actually comes out on paper.
"""

from __future__ import annotations

import fixtures as F
from conftest import all_text, images_per_page, page_texts

from printscript.pipeline import ConversionOptions, convert_docx


def convert(builder, body, **options):
    return convert_docx(builder.build(body), ConversionOptions(**options),
                        title='Testscript')


# ── 1. Comments ──────────────────────────────────────────────────────────────

def test_comment_text_never_reaches_the_pdf(builder):
    builder.add_comments(F.comments_part('GEHEIME REDACTIENOTITIE'))
    body = (F.paragraph('Gewone zin.') +
            F.commented_paragraph('Zin met opmerking.', 'GEHEIME REDACTIENOTITIE') +
            F.DEFAULT_SECTION)

    result = convert(builder, body)

    assert 'GEHEIME REDACTIENOTITIE' not in all_text(result.pdf)
    assert 'Zin met opmerking.' in all_text(result.pdf)
    assert result.report.comment_markers_removed >= 3
    assert result.report.comment_parts_removed == 1


def test_comment_parts_are_dropped_from_the_package(builder):
    from printscript.clean import clean, count_comment_markers
    from printscript.package import Package

    builder.add_comments(F.comments_part('weg hiermee'))
    package = Package(builder.build(
        F.commented_paragraph('Tekst', 'weg hiermee') + F.DEFAULT_SECTION))

    assert count_comment_markers(package) > 0
    clean(package)
    assert count_comment_markers(package) == 0
    assert not package.has_part('word/comments.xml')


# ── 2. Highlighting ──────────────────────────────────────────────────────────

def test_highlighting_is_removed_and_text_colour_survives(builder):
    body = (F.paragraph('Gemarkeerd rood',
                        run_properties='<w:highlight w:val="yellow"/>'
                                       '<w:color w:val="FF0000"/>') +
            F.paragraph('Google-arcering',
                        run_properties='<w:shd w:val="clear" w:fill="FFFF00"/>') +
            F.DEFAULT_SECTION)

    result = convert(builder, body)

    assert result.report.highlights_removed == 1
    assert result.report.shadings_removed == 1
    assert 'Gemarkeerd rood' in all_text(result.pdf)

    from printscript.docxhtml import render
    from printscript.clean import clean
    from printscript.package import Package

    package = Package(builder.build(body))
    clean(package)
    html = render(package).to_html()
    assert 'background' not in html
    assert '#FF0000' in html          # the red text colour is untouched


def test_highlighting_inside_a_style_is_removed_too(builder):
    builder.add_styles(
        '<w:styles ' + F.NS_DECL + '>'
        '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
        '<w:name w:val="Normal"/></w:style>'
        '<w:style w:type="character" w:styleId="Marker">'
        '<w:name w:val="Marker"/>'
        '<w:rPr><w:highlight w:val="green"/></w:rPr></w:style>'
        '</w:styles>')
    body = (F.paragraph('Via stijl gemarkeerd',
                        run_properties='<w:rStyle w:val="Marker"/>') +
            F.DEFAULT_SECTION)

    result = convert(builder, body)

    assert result.report.highlights_removed == 1


# ── 3. Images after page 1 ───────────────────────────────────────────────────

def test_images_after_an_explicit_page_break_are_removed(builder):
    cover = builder.add_image(F.png_bytes(60, 60, (30, 120, 220)))
    later = builder.add_image(F.png_bytes(60, 60, (240, 160, 20)))
    body = (F.paragraph('Omslag') +
            '<w:p>' + F.image_run(cover) + '</w:p>' +
            F.page_break_paragraph() +
            F.paragraph('Pagina twee') +
            '<w:p>' + F.image_run(later) + '</w:p>' +
            F.DEFAULT_SECTION)

    result = convert(builder, body)

    assert result.page_count == 2
    assert result.images_removed == 1
    assert images_per_page(result.pdf) == [1, 0]


def test_images_that_only_flow_onto_page_two_are_removed(builder):
    """
    The hard case: no page break anywhere.  Whether an image sits on page 2 is
    a layout fact, so it can only be answered after laying the document out.
    """
    cover = builder.add_image(F.png_bytes(60, 60, (30, 120, 220)))
    later = builder.add_image(F.png_bytes(60, 60, (240, 160, 20)))
    filler = ''.join(F.paragraph('Vulregel %d met voldoende tekst erin.' % i)
                     for i in range(60))
    body = ('<w:p>' + F.image_run(cover) + '</w:p>' + filler +
            '<w:p>' + F.image_run(later) + '</w:p>' + F.DEFAULT_SECTION)

    result = convert(builder, body)

    assert result.page_count >= 2
    assert result.images_removed == 1
    assert images_per_page(result.pdf)[0] == 1
    assert sum(images_per_page(result.pdf)[1:]) == 0


def test_page_one_images_are_kept_when_everything_fits_on_one_page(builder):
    cover = builder.add_image(F.png_bytes(60, 60, (30, 120, 220)))
    body = ('<w:p>' + F.image_run(cover) + '</w:p>' + F.paragraph('Kort') +
            F.DEFAULT_SECTION)

    result = convert(builder, body)

    assert result.images_removed == 0
    assert images_per_page(result.pdf) == [1]


def test_the_image_rule_can_be_switched_off(builder):
    cover = builder.add_image(F.png_bytes(60, 60, (30, 120, 220)))
    later = builder.add_image(F.png_bytes(60, 60, (240, 160, 20)))
    body = ('<w:p>' + F.image_run(cover) + '</w:p>' + F.page_break_paragraph() +
            '<w:p>' + F.image_run(later) + '</w:p>' + F.DEFAULT_SECTION)

    result = convert(builder, body, images_first_page_only=False)

    assert result.images_removed == 0
    assert images_per_page(result.pdf) == [1, 1]


def test_header_and_footer_images_are_never_removed(builder):
    # The logo belongs to the footer part, so it is that part's relationship.
    logo = builder.add_image(F.png_bytes(20, 20, (10, 10, 10)),
                             owner='word/footer1.xml')
    footer = builder.add_footer('<w:p>' + F.image_run(logo, 190500, 190500) + '</w:p>')
    body = (F.paragraph('Een') + F.page_break_paragraph() + F.paragraph('Twee') +
            F.section_with_footer(footer))

    result = convert(builder, body)

    assert result.images_removed == 0
    assert images_per_page(result.pdf) == [1, 1]


# ── 4. Page numbering ────────────────────────────────────────────────────────

def test_page_field_becomes_a_real_counter(builder):
    footer = builder.add_footer(F.page_field_footer('Pagina '))
    body = (F.paragraph('Een') + F.page_break_paragraph() +
            F.paragraph('Twee') + F.page_break_paragraph() +
            F.paragraph('Drie') + F.section_with_footer(footer))

    texts = page_texts(convert(builder, body).pdf)

    assert 'Pagina 1' in texts[0]
    assert 'Pagina 2' in texts[1]
    assert 'Pagina 3' in texts[2]
    # The value Word had cached in the field result must not leak through.
    assert 'Pagina 7' not in '\n'.join(texts)


def test_numpages_field_counts_the_real_pages(builder):
    footer = builder.add_footer(
        '<w:p><w:r><w:t xml:space="preserve">van </w:t></w:r>'
        '<w:fldSimple w:instr=" NUMPAGES "><w:r><w:t>99</w:t></w:r></w:fldSimple>'
        '</w:p>')
    body = (F.paragraph('Een') + F.page_break_paragraph() + F.paragraph('Twee') +
            F.section_with_footer(footer))

    texts = page_texts(convert(builder, body).pdf)

    assert 'van 2' in texts[0]
    assert '99' not in '\n'.join(texts)


def test_a_document_without_a_footer_gets_page_numbers(builder):
    body = (F.paragraph('Een') + F.page_break_paragraph() + F.paragraph('Twee') +
            F.DEFAULT_SECTION)

    texts = page_texts(convert(builder, body).pdf)

    assert texts[0].strip().endswith('1')
    assert texts[1].strip().endswith('2')


def test_page_numbers_can_be_left_off_the_cover(builder):
    body = (F.paragraph('Omslag') + F.page_break_paragraph() +
            F.paragraph('Inhoud') + F.DEFAULT_SECTION)

    texts = page_texts(convert(builder, body,
                               page_numbers_on_first_page=False).pdf)

    assert '1' not in texts[0]
    assert texts[1].strip().endswith('2')


def test_adding_page_numbers_can_be_switched_off(builder):
    body = F.paragraph('Alleen tekst') + F.DEFAULT_SECTION

    texts = page_texts(convert(builder, body, add_page_numbers=False).pdf)

    assert texts[0].strip() == 'Alleen tekst'
