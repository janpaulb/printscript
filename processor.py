"""
Word-to-PDF processor.

Transforms a .docx file into a print-ready PDF by:
  1. Removing all comments
  2. Removing all highlighting (arceringen) while preserving text colour
  3. Removing all images except those on page 1
  4. Keeping page-number footer intact
"""

import os
import subprocess
import shutil
import tempfile
from copy import deepcopy

from docx import Document
from docx.oxml.ns import qn
from lxml import etree


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _iter_body_elements(doc):
    """Yield all direct children of <w:body> in order."""
    body = doc.element.body
    for child in body:
        yield child


def _find_first_pagebreak_index(doc):
    """
    Return the index (among body children) of the first explicit page break.

    An explicit page break is either:
      - <w:br w:type="page"/> inside a run
      - A paragraph whose section properties use type="nextPage"

    Returns None if no explicit page break is found (all content is page 1).
    """
    body = doc.element.body
    children = list(body)

    for idx, child in enumerate(children):
        # Check for <w:br w:type="page"/> anywhere inside this element
        for br in child.iter(qn('w:br')):
            if br.get(qn('w:type')) == 'page':
                return idx

        # Check for section break of type nextPage inside a paragraph's pPr
        for sectPr in child.iter(qn('w:sectPr')):
            t = sectPr.find(qn('w:type'))
            if t is not None and t.get(qn('w:val')) in ('nextPage', 'evenPage', 'oddPage'):
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

    # Tags to remove entirely from the body XML
    comment_tags = [
        qn('w:commentRangeStart'),
        qn('w:commentRangeEnd'),
    ]
    for tag in comment_tags:
        for elem in body.findall(f'.//{tag}'):
            parent = elem.getparent()
            if parent is not None:
                parent.remove(elem)

    # <w:r> elements that contain only a <w:commentReference> should be removed
    for run in body.findall(f'.//{qn("w:r")}'):
        children = list(run)
        rpr = run.find(qn('w:rPr'))
        non_rpr = [c for c in children if c.tag != qn('w:rPr')]
        if len(non_rpr) == 1 and non_rpr[0].tag == qn('w:commentReference'):
            parent = run.getparent()
            if parent is not None:
                parent.remove(run)

    # Remove the comments part from the package relationships so LibreOffice
    # does not render a comment panel.
    try:
        part = doc.part
        rels_to_drop = [
            key for key, rel in part.rels.items()
            if 'comment' in rel.reltype.lower()
        ]
        for key in rels_to_drop:
            del part.rels[key]
    except Exception:
        pass  # Best-effort; missing part is fine


# ---------------------------------------------------------------------------
# 2. Remove highlighting
# ---------------------------------------------------------------------------

def remove_highlighting(doc):
    """
    Remove <w:highlight> from every run's rPr, leaving <w:color> (text colour)
    and all other run properties intact.
    """
    # Paragraphs in the body
    for paragraph in doc.paragraphs:
        _strip_highlight_from_runs(paragraph.runs)

    # Paragraphs inside tables
    for table in doc.tables:
        for row in table.rows:
            for cell in row.cells:
                for paragraph in cell.paragraphs:
                    _strip_highlight_from_runs(paragraph.runs)

    # Headers and footers
    for section in doc.sections:
        for hf in [section.header, section.footer,
                   section.first_page_header, section.first_page_footer,
                   section.even_page_header, section.even_page_footer]:
            try:
                for paragraph in hf.paragraphs:
                    _strip_highlight_from_runs(paragraph.runs)
            except Exception:
                pass


def _strip_highlight_from_runs(runs):
    for run in runs:
        rpr = run._r.find(qn('w:rPr'))
        if rpr is not None:
            for highlight in rpr.findall(qn('w:highlight')):
                rpr.remove(highlight)
            # Also remove shading (w:shd) used as a highlight substitute
            # Only remove if it mimics a highlight (fill != "auto" and no pattern)
            for shd in rpr.findall(qn('w:shd')):
                fill = shd.get(qn('w:fill'))
                theme_fill = shd.get(qn('w:themeFill'))
                val = shd.get(qn('w:val'), 'clear')
                # Remove highlight-style shading (solid fill, no complex pattern)
                if val in ('clear', 'solid') and (fill or theme_fill):
                    rpr.remove(shd)


# ---------------------------------------------------------------------------
# 3. Remove images after page 1
# ---------------------------------------------------------------------------

def remove_images_after_page_one(doc):
    """
    Remove all inline and floating images that appear after the first page break.
    Images on page 1 (before the first page break) are preserved.
    """
    body = doc.element.body
    children = list(body)
    pagebreak_idx = _find_first_pagebreak_index(doc)

    if pagebreak_idx is None:
        # No explicit page break found — nothing to remove
        return

    # Remove all <w:drawing> and <v:shape> elements from elements after the
    # first page break.
    for child in children[pagebreak_idx + 1:]:
        _remove_drawing_elements(child)


def _remove_drawing_elements(element):
    """Recursively remove all drawing/image elements from an XML element."""
    drawing_tags = [
        qn('w:drawing'),
        qn('w:pict'),
        '{urn:schemas-microsoft-com:vml}shape',
        '{urn:schemas-microsoft-com:vml}image',
    ]
    for tag in drawing_tags:
        for drawing in element.findall(f'.//{tag}'):
            parent = drawing.getparent()
            if parent is not None:
                parent.remove(drawing)


# ---------------------------------------------------------------------------
# 4. Convert processed .docx → PDF via LibreOffice
# ---------------------------------------------------------------------------

def convert_to_pdf(docx_path: str, output_dir: str) -> str:
    """
    Convert a .docx file to PDF using LibreOffice headless.
    Returns the path to the generated PDF file.
    Raises RuntimeError if conversion fails.
    """
    result = subprocess.run(
        [
            'libreoffice',
            '--headless',
            '--convert-to', 'pdf',
            '--outdir', output_dir,
            docx_path,
        ],
        capture_output=True,
        text=True,
        timeout=120,
    )

    if result.returncode != 0:
        raise RuntimeError(
            f'LibreOffice conversion failed:\n{result.stderr}'
        )

    base = os.path.splitext(os.path.basename(docx_path))[0]
    pdf_path = os.path.join(output_dir, base + '.pdf')
    if not os.path.exists(pdf_path):
        raise RuntimeError(
            f'LibreOffice did not produce expected PDF at {pdf_path}.\n'
            f'stdout: {result.stdout}\nstderr: {result.stderr}'
        )
    return pdf_path


# ---------------------------------------------------------------------------
# Public entry point
# ---------------------------------------------------------------------------

def process(input_docx_path: str, output_pdf_path: str) -> None:
    """
    Full pipeline: load → clean → save modified docx → convert to PDF.
    The intermediate .docx is written to a temporary directory.
    """
    with tempfile.TemporaryDirectory() as tmpdir:
        # Load the original document
        doc = Document(input_docx_path)

        # Apply all transformations
        remove_comments(doc)
        remove_highlighting(doc)
        remove_images_after_page_one(doc)

        # Save the cleaned document
        cleaned_docx = os.path.join(tmpdir, 'cleaned.docx')
        doc.save(cleaned_docx)

        # Convert to PDF
        pdf_path = convert_to_pdf(cleaned_docx, tmpdir)

        # Move to the requested output location
        shutil.move(pdf_path, output_pdf_path)
