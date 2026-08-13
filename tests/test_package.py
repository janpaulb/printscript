"""The OOXML package layer and the small pieces around it."""

from __future__ import annotations

import io
import zipfile

import fixtures as F
import pytest

from printscript.package import (CONTENT_TYPES, InvalidDocxError, Package,
                                 content_type_of)
from printscript.pipeline import safe_filename


def simple_docx(builder) -> bytes:
    return builder.build(F.paragraph('Inhoud') + F.DEFAULT_SECTION)


# ── Loading ──────────────────────────────────────────────────────────────────

def test_a_valid_package_exposes_its_parts(builder):
    package = Package(simple_docx(builder))

    assert package.main_part_name() == 'word/document.xml'
    assert package.has_part('word/styles.xml')
    assert content_type_of(package, 'word/document.xml').endswith('main+xml')


@pytest.mark.parametrize('data, fragment', [
    (b'', 'geen geldig .docx'),
    (b'dit is gewoon tekst', 'geen geldig .docx'),
    (b'%PDF-1.7\n%...', 'geen geldig .docx'),
])
def test_non_zip_input_is_refused(data, fragment):
    with pytest.raises(InvalidDocxError) as error:
        Package(data)
    assert fragment in str(error.value)


def test_a_zip_without_content_types_is_refused():
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w') as zf:
        zf.writestr('hello.txt', 'hi')

    with pytest.raises(InvalidDocxError) as error:
        Package(buffer.getvalue())
    assert 'Content_Types' in str(error.value)


def test_a_zip_without_a_main_document_is_refused():
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w') as zf:
        zf.writestr(CONTENT_TYPES,
                    '<Types xmlns="http://schemas.openxmlformats.org/package/'
                    '2006/content-types"/>')
        zf.writestr('xl/workbook.xml', '<workbook/>')

    with pytest.raises(InvalidDocxError) as error:
        Package(buffer.getvalue())
    assert 'hoofddocument' in str(error.value)


# ── Relationships ────────────────────────────────────────────────────────────

def test_relationships_resolve_to_absolute_part_names(builder):
    footer_rel = builder.add_footer('<w:p><w:r><w:t>voet</w:t></w:r></w:p>')
    image_rel = builder.add_image(F.png_bytes(4, 4))
    package = Package(builder.build(
        F.paragraph('x') + F.section_with_footer(footer_rel)))

    main = package.main_part_name()
    assert package.related_part_name(main, footer_rel) == 'word/footer1.xml'
    assert package.related_part_name(main, image_rel) == 'word/media/image1.png'
    assert package.related_blob(main, image_rel).startswith(b'\x89PNG')
    assert package.related_part_name(main, 'rId999') is None


def test_dropping_a_part_also_drops_its_content_type_and_relationship(builder):
    builder.add_comments(F.comments_part('weg'))
    package = Package(builder.build(F.paragraph('x') + F.DEFAULT_SECTION))
    main = package.main_part_name()

    assert 'comments+xml' in content_type_of(package, 'word/comments.xml')
    package.drop_part('word/comments.xml')

    assert not package.has_part('word/comments.xml')
    # The override is gone; only the generic .xml default is left.
    assert content_type_of(package, 'word/comments.xml') == 'application/xml'
    assert all(rel.kind != 'comments' for rel in package.rels(main).values())


def test_the_package_round_trips_through_bytes(builder):
    package = Package(simple_docx(builder))
    package.drop_part('word/styles.xml')

    reloaded = Package(package.to_bytes())

    assert not reloaded.has_part('word/styles.xml')
    assert reloaded.main_part_name() == 'word/document.xml'


# ── Download names ───────────────────────────────────────────────────────────

@pytest.mark.parametrize('raw, expected', [
    ('Aflevering 3', 'Aflevering 3'),
    ('Aflevering 3.docx', 'Aflevering 3'),
    ('../../etc/passwd', 'passwd'),
    ('C:\\Users\\jan\\script.docx', 'script'),
    ('scène été (v2)', 'scène été (v2)'),
    ('naam/met/slashes', 'slashes'),
    ('', 'document'),
    ('   ', 'document'),
    ('...', 'document'),
    ('a' * 200, 'a' * 80),
])
def test_download_names_are_safe_and_readable(raw, expected):
    assert safe_filename(raw) == expected
