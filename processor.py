"""
Word-to-PDF processor.

Transforms a .docx file into a print-ready PDF by:
  1. Removing all comments
  2. Removing all highlighting (arceringen) while preserving text colour
  3. Removing all images except those on page 1
  4. Converting the cleaned .docx to PDF via WeasyPrint + mammoth
"""

import logging
import os
import shutil
import tempfile

from docx import Document
from docx.oxml.ns import qn


log = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

# Markup Compatibility namespace (Word 2010+ wraps images in mc:AlternateContent)
_MC_NS = 'http://schemas.openxmlformats.org/markup-compatibility/2006'
_MC_ALT = f'{{{_MC_NS}}}AlternateContent'


def _find_first_pagebreak_index(doc):
    """
    Return the index (among body children) of the element that contains the
    first page boundary.

    A page boundary is detected by:
      1. An explicit <w:br w:type="page"/> inside a run, OR
      2. A <w:sectPr> inside a paragraph's <w:pPr> with type nextPage /
         evenPage / oddPage (or no type, which defaults to nextPage).

    The body-level <w:sectPr> (always the sole direct-child sectPr of <w:body>)
    describes the document's last section and is intentionally skipped.

    Returns None if no page break is found (treat whole document as page 1).
    """
    body = doc.element.body
    children = list(body)
    body_sectPr = body.find(qn('w:sectPr'))

    for idx, child in enumerate(children):
        # Explicit page break
        for br in child.iter(qn('w:br')):
            if br.get(qn('w:type')) == 'page':
                return idx

        # Section break inside a paragraph (pPr/sectPr)
        for sectPr in child.iter(qn('w:sectPr')):
            if sectPr is body_sectPr:
                continue
            t = sectPr.find(qn('w:type'))
            if t is None or t.get(qn('w:val')) in ('nextPage', 'evenPage', 'oddPage'):
                return idx

    return None


# ---------------------------------------------------------------------------
# 1. Remove comments
# ---------------------------------------------------------------------------

def remove_comments(doc):
    """
    Strip all comment markup from the document body and remove the comments
    relationship part so the converted PDF has no comment side-panels.
    """
    body = doc.element.body

    for tag in (qn('w:commentRangeStart'), qn('w:commentRangeEnd')):
        for elem in body.findall(f'.//{tag}'):
            parent = elem.getparent()
            if parent is not None:
                parent.remove(elem)

    # Runs whose sole content is a commentReference can be dropped entirely
    for run in body.findall(f'.//{qn("w:r")}'):
        non_rpr = [c for c in run if c.tag != qn('w:rPr')]
        if len(non_rpr) == 1 and non_rpr[0].tag == qn('w:commentReference'):
            parent = run.getparent()
            if parent is not None:
                parent.remove(run)

    # Drop the comments relationship so no comment panel appears in the PDF
    try:
        rels_to_drop = [
            key for key, rel in doc.part.rels.items()
            if 'comment' in rel.reltype.lower()
        ]
        for key in rels_to_drop:
            del doc.part.rels[key]
    except Exception:
        pass


# ---------------------------------------------------------------------------
# 2. Remove highlighting
# ---------------------------------------------------------------------------

def remove_highlighting(doc):
    """
    Remove all highlighting/shading from every run and paragraph, leaving
    <w:color> (text colour) and all other properties intact.
    """
    # Body paragraphs
    for paragraph in doc.paragraphs:
        _strip_highlight_from_paragraph(paragraph)

    # Table cells
    for table in doc.tables:
        for row in table.rows:
            for cell in row.cells:
                for paragraph in cell.paragraphs:
                    _strip_highlight_from_paragraph(paragraph)

    # Headers and footers
    for section in doc.sections:
        for hf in (
            section.header, section.footer,
            section.first_page_header, section.first_page_footer,
            section.even_page_header, section.even_page_footer,
        ):
            try:
                for paragraph in hf.paragraphs:
                    _strip_highlight_from_paragraph(paragraph)
            except Exception:
                pass


def _strip_highlight_from_paragraph(paragraph):
    # Run-level highlight and shading
    for run in paragraph.runs:
        rpr = run._r.find(qn('w:rPr'))
        if rpr is not None:
            for hl in rpr.findall(qn('w:highlight')):
                rpr.remove(hl)
            for shd in rpr.findall(qn('w:shd')):
                if shd.get(qn('w:val'), 'clear') in ('clear', 'solid'):
                    rpr.remove(shd)

    # Paragraph-level shading (e.g. whole-paragraph background highlight)
    ppr = paragraph._p.find(qn('w:pPr'))
    if ppr is not None:
        for shd in ppr.findall(qn('w:shd')):
            if shd.get(qn('w:val'), 'clear') in ('clear', 'solid'):
                ppr.remove(shd)


# ---------------------------------------------------------------------------
# 3. Remove images after page 1
# ---------------------------------------------------------------------------

def remove_images_after_page_one(doc):
    """
    Remove all images that appear after the first page break.
    Images on page 1 are preserved.
    """
    body = doc.element.body
    children = list(body)
    pagebreak_idx = _find_first_pagebreak_index(doc)

    if pagebreak_idx is None:
        return  # No page break → whole doc is page 1, nothing to remove

    for child in children[pagebreak_idx + 1:]:
        _remove_drawing_elements(child)


# Image container tags — includes mc:AlternateContent (Word 2010+ images)
_DRAWING_TAGS = frozenset([
    qn('w:drawing'),
    qn('w:pict'),
    _MC_ALT,
    '{urn:schemas-microsoft-com:vml}shape',
    '{urn:schemas-microsoft-com:vml}image',
])


def _remove_drawing_elements(element):
    """Remove all image/drawing containers from an XML subtree."""
    for tag in _DRAWING_TAGS:
        for node in element.findall(f'.//{tag}'):
            parent = node.getparent()
            if parent is not None:
                parent.remove(node)


# ---------------------------------------------------------------------------
# 4. Convert processed .docx → PDF via WeasyPrint + mammoth
# ---------------------------------------------------------------------------

def _validate_pdf(pdf_path: str) -> None:
    """Raise RuntimeError if the PDF is missing, too small, or has no %PDF header."""
    if not os.path.exists(pdf_path):
        raise RuntimeError('Geen PDF gegenereerd op het verwachte pad.')
    size = os.path.getsize(pdf_path)
    if size < 1024:
        raise RuntimeError(f'Ongeldig PDF-bestand ({size} bytes).')
    with open(pdf_path, 'rb') as f:
        if f.read(4) != b'%PDF':
            raise RuntimeError('Beschadigd PDF-bestand (ongeldige header).')


def convert_to_pdf(docx_path: str, output_dir: str) -> str:
    """
    Convert a .docx file to PDF using mammoth (DOCX→HTML) + WeasyPrint (HTML→PDF).

    Pure Python — no external processes, no display, no VCL plugins.
    Works headlessly on macOS (PyInstaller frozen app) and Linux (Docker).

    Requires:  pip install mammoth weasyprint
    """
    import base64 as _b64

    import mammoth          # type: ignore
    import weasyprint       # type: ignore

    base     = os.path.splitext(os.path.basename(docx_path))[0]
    pdf_path = os.path.join(output_dir, base + '.pdf')

    # ── Extract header / footer text via python-docx ─────────────────────────
    header_html = footer_html = ''
    try:
        _doc = Document(docx_path)
        for _sec in _doc.sections:
            if _sec.header and not _sec.header.is_linked_to_previous:
                _ht = '\n'.join(p.text for p in _sec.header.paragraphs if p.text.strip())
                if _ht:
                    header_html = f'<div class="doc-header">{_ht}</div>'
            if _sec.footer and not _sec.footer.is_linked_to_previous:
                _ft = '\n'.join(p.text for p in _sec.footer.paragraphs if p.text.strip())
                if _ft:
                    footer_html = f'<div class="doc-footer">{_ft}</div>'
            break  # first section only
    except Exception:
        pass  # best-effort; missing header/footer is acceptable

    # ── DOCX body → HTML via mammoth ─────────────────────────────────────────
    def _embed_image(image):
        with image.open() as f:
            data = _b64.b64encode(f.read()).decode()
        return {'src': f'data:{image.content_type};base64,{data}'}

    with open(docx_path, 'rb') as f:
        _result = mammoth.convert_to_html(
            f,
            convert_image=mammoth.images.img_element(_embed_image),
        )
    body_html = _result.value

    # ── Minimal A4 stylesheet ─────────────────────────────────────────────────
    css = """
        @page { size: A4; margin: 2.5cm; }
        body {
            font-family: Arial, "Liberation Sans", sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #000;
            margin: 0;
        }
        p                 { margin: 0 0 6pt 0; }
        h1                { font-size: 16pt; font-weight: bold; margin: 12pt 0 6pt; }
        h2                { font-size: 14pt; font-weight: bold; margin: 10pt 0 6pt; }
        h3                { font-size: 12pt; font-weight: bold; margin:  8pt 0 4pt; }
        h4, h5, h6        { font-size: 11pt; font-weight: bold; margin:  6pt 0 3pt; }
        table             { border-collapse: collapse; width: 100%; margin: 6pt 0; }
        td, th            { border: 1px solid #ccc; padding: 3pt 5pt; font-size: 10pt; }
        img               { max-width: 100%; height: auto; }
        ol, ul            { margin: 0 0 6pt; padding-left: 20pt; }
        li                { margin-bottom: 2pt; }
        .doc-header {
            font-size: 9pt; color: #555;
            border-bottom: 1px solid #ccc;
            padding-bottom: 4pt; margin-bottom: 12pt;
        }
        .doc-footer {
            font-size: 9pt; color: #555;
            border-top: 1px solid #ccc;
            padding-top: 4pt; margin-top: 12pt;
        }
    """

    html = (
        '<!DOCTYPE html><html lang="nl"><head>'
        '<meta charset="utf-8">'
        f'<style>{css}</style>'
        '</head><body>'
        f'{header_html}{body_html}{footer_html}'
        '</body></html>'
    )

    # ── HTML → PDF via WeasyPrint ─────────────────────────────────────────────
    weasyprint.HTML(string=html, base_url=output_dir).write_pdf(pdf_path)
    _validate_pdf(pdf_path)
    return pdf_path


# ---------------------------------------------------------------------------
# Public entry point
# ---------------------------------------------------------------------------

def process(input_docx_path: str, output_pdf_path: str) -> None:
    """
    Full pipeline: load → clean → save cleaned docx → convert to PDF.
    """
    with tempfile.TemporaryDirectory() as tmpdir:
        try:
            doc = Document(input_docx_path)
        except Exception as exc:
            raise RuntimeError(
                'Kan het Word-document niet openen. '
                'Controleer of het bestand niet beschadigd of versleuteld is.'
            ) from exc

        remove_comments(doc)
        remove_highlighting(doc)
        remove_images_after_page_one(doc)

        cleaned_docx = os.path.join(tmpdir, 'cleaned.docx')
        doc.save(cleaned_docx)

        pdf_path = convert_to_pdf(cleaned_docx, tmpdir)
        shutil.move(pdf_path, output_pdf_path)
