"""
Word-to-PDF processor.

Transforms a .docx file into a print-ready PDF by:
  1. Removing all comments
  2. Removing all highlighting (arceringen) while preserving text colour
  3. Removing all images except those on page 1
  4. Keeping page-number footer intact
"""

import os
import shutil
import subprocess
import sys
import tempfile
import uuid

from docx import Document
from docx.oxml.ns import qn


# ---------------------------------------------------------------------------
# LibreOffice binary detection
# ---------------------------------------------------------------------------

_LIBREOFFICE_BINARY: str | None = None  # module-level cache


def _find_libreoffice() -> str:
    """
    Return the path to the LibreOffice soffice binary.

    Priority on macOS (standalone .app build):
      1. User-library version  – updated automatically by updater.py
      2. Bundled version       – shipped inside the .app at build time
      3. System-installed      – /Applications/LibreOffice.app or PATH

    On Linux the function expects 'libreoffice' on PATH.

    The result is cached at module level so repeated conversions skip
    the filesystem probing overhead.
    """
    global _LIBREOFFICE_BINARY
    if _LIBREOFFICE_BINARY and os.path.isfile(_LIBREOFFICE_BINARY):
        return _LIBREOFFICE_BINARY

    import shutil as _shutil

    if sys.platform == 'darwin':
        # 1 + 2: ask the updater (handles both user-library and bundled paths)
        try:
            from updater import get_active_soffice
            path = get_active_soffice()
            if path and os.path.isfile(path):
                _LIBREOFFICE_BINARY = path
                return path
        except ImportError:
            pass  # updater not available (e.g. running tests on Linux)

        # 3: system-installed fallback
        candidates = [
            _shutil.which('libreoffice'),
            _shutil.which('soffice'),
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
            '/opt/homebrew/bin/libreoffice',
            '/usr/local/bin/libreoffice',
        ]
        for path in candidates:
            if path and os.path.isfile(path):
                _LIBREOFFICE_BINARY = path
                return path

        raise RuntimeError(
            'LibreOffice niet gevonden.\n'
            'Download en installeer het via https://www.libreoffice.org/download/'
        )

    # Linux / other: expect 'libreoffice' on PATH
    binary = _shutil.which('libreoffice') or _shutil.which('soffice')
    if not binary:
        raise RuntimeError(
            'LibreOffice niet gevonden. Installeer het via:\n'
            '  sudo apt-get install libreoffice-writer'
        )
    _LIBREOFFICE_BINARY = binary
    return binary


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
    # The body-level sectPr is a direct child; find() only searches direct children.
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
            # No <w:type> means nextPage (Word default)
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

    # Drop the comments part from the package so LibreOffice shows no panel
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
# 4. Convert processed .docx → PDF via LibreOffice
# ---------------------------------------------------------------------------

def convert_to_pdf(docx_path: str, output_dir: str) -> str:
    """
    Convert a .docx file to PDF using LibreOffice headless.

    Each call gets an isolated LibreOffice user profile so that concurrent
    conversions don't clash on the shared default profile directory.

    Returns the path to the generated PDF.
    Raises RuntimeError on failure.
    """
    # Unique profile dir prevents lock conflicts under concurrent load
    profile_dir = os.path.join(output_dir, f'lo_profile_{uuid.uuid4().hex}')
    os.makedirs(profile_dir, exist_ok=True)

    result = subprocess.run(
        [
            _find_libreoffice(),
            '--headless',
            f'-env:UserInstallation=file://{profile_dir}',
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
            f'PDF-conversie mislukt (LibreOffice rc={result.returncode}):\n'
            f'{result.stderr}'
        )

    base = os.path.splitext(os.path.basename(docx_path))[0]
    pdf_path = os.path.join(output_dir, base + '.pdf')
    if not os.path.exists(pdf_path):
        raise RuntimeError(
            f'LibreOffice produceerde geen PDF op het verwachte pad.\n'
            f'stdout: {result.stdout}\nstderr: {result.stderr}'
        )
    return pdf_path


# ---------------------------------------------------------------------------
# Public entry point
# ---------------------------------------------------------------------------

def process(input_docx_path: str, output_pdf_path: str) -> None:
    """
    Full pipeline: load → clean → save cleaned docx → convert to PDF.
    """
    with tempfile.TemporaryDirectory() as tmpdir:
        doc = Document(input_docx_path)

        remove_comments(doc)
        remove_highlighting(doc)
        remove_images_after_page_one(doc)

        cleaned_docx = os.path.join(tmpdir, 'cleaned.docx')
        doc.save(cleaned_docx)

        pdf_path = convert_to_pdf(cleaned_docx, tmpdir)
        shutil.move(pdf_path, output_pdf_path)
