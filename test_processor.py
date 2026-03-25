"""
Basic smoke test – creates a .docx with comments, highlights and images,
runs the processor, and checks the output.
"""

import os
import sys
import tempfile

from docx import Document
from docx.shared import RGBColor, Pt
from docx.oxml.ns import qn
from lxml import etree

sys.path.insert(0, os.path.dirname(__file__))
from processor import remove_comments, remove_highlighting, remove_images_after_page_one


def _add_highlight(run, color='yellow'):
    """Add a highlight to a run via raw XML."""
    rpr = run._r.get_or_add_rPr()
    hl = etree.SubElement(rpr, qn('w:highlight'))
    hl.set(qn('w:val'), color)


def _add_page_break(doc):
    from docx.oxml import OxmlElement
    p = doc.add_paragraph()
    run = p.add_run()
    br = OxmlElement('w:br')
    br.set(qn('w:type'), 'page')
    run._r.append(br)


def _count_drawings(doc):
    return len(doc.element.body.findall(f'.//{qn("w:drawing")}'))


def _count_highlights(doc):
    return len(doc.element.body.findall(f'.//{qn("w:highlight")}'))


def _count_comment_markers(doc):
    count = 0
    for tag in ['w:commentRangeStart', 'w:commentRangeEnd', 'w:commentReference']:
        count += len(doc.element.body.findall(f'.//{qn(tag)}'))
    return count


def build_test_doc(path):
    doc = Document()

    # Page 1: text + image + highlight + comment marker
    p1 = doc.add_paragraph('Pagina 1 tekst')
    run1 = p1.add_run(' gearceerd')
    _add_highlight(run1, 'yellow')

    # Fake comment range start
    from docx.oxml import OxmlElement
    crs = OxmlElement('w:commentRangeStart')
    crs.set(qn('w:id'), '1')
    p1._p.insert(0, crs)

    # Inline image on page 1 (tiny 1×1 PNG via python-docx fixture)
    # We'll just add a paragraph and manually insert a w:drawing placeholder
    p_img1 = doc.add_paragraph('Afbeelding pagina 1:')
    from docx.oxml import OxmlElement
    drawing = OxmlElement('w:drawing')
    p_img1._p.append(drawing)

    # Explicit page break
    _add_page_break(doc)

    # Page 2+: image that SHOULD be removed
    p2 = doc.add_paragraph('Pagina 2 tekst')
    drawing2 = OxmlElement('w:drawing')
    p2._p.append(drawing2)

    # Another highlight on page 2
    run2 = p2.add_run(' ook gearceerd')
    _add_highlight(run2, 'green')

    doc.save(path)
    return doc


def test_all():
    with tempfile.TemporaryDirectory() as tmpdir:
        path = os.path.join(tmpdir, 'test.docx')
        build_test_doc(path)

        doc = Document(path)

        # Verify setup
        assert _count_highlights(doc) == 2, f"Expected 2 highlights, got {_count_highlights(doc)}"
        assert _count_drawings(doc) == 2, f"Expected 2 drawings, got {_count_drawings(doc)}"
        assert _count_comment_markers(doc) >= 1, "Expected at least 1 comment marker"

        # Run transforms
        remove_comments(doc)
        remove_highlighting(doc)
        remove_images_after_page_one(doc)

        # Check results
        hl = _count_highlights(doc)
        assert hl == 0, f"Highlights not fully removed, {hl} remain"
        print(f"  [OK] Highlights removed: {hl} remain")

        drawings = _count_drawings(doc)
        assert drawings == 1, f"Expected 1 drawing (page 1 only), got {drawings}"
        print(f"  [OK] Drawings: {drawings} remain (page 1 only)")

        cm = _count_comment_markers(doc)
        assert cm == 0, f"Comment markers not removed, {cm} remain"
        print(f"  [OK] Comment markers removed: {cm} remain")

    print("\nAll tests passed.")


if __name__ == '__main__':
    test_all()
