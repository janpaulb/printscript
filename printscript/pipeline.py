"""
The conversion pipeline.

    Google Docs URL ─┐
                     ├─→ .docx bytes → clean → render HTML → PDF
    uploaded .docx  ─┘

Every step is pure: bytes in, bytes out, no temporary files, nothing written to
disk.  That is what makes the whole thing testable end to end.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field
from typing import List, Optional

from . import gdocs
from .clean import CleanReport, clean
from .docxhtml import RenderOptions, render
from .package import InvalidDocxError, Package
from .pdf import render_pdf

log = logging.getLogger(__name__)

MAX_UPLOAD_BYTES = 50 * 1024 * 1024

_UNSAFE_NAME = re.compile(r'[^\w \-()\[\]&.,]', re.UNICODE)


@dataclass
class ConversionOptions:
    """Everything the caller can influence about the printed result."""

    images_first_page_only: bool = True
    add_page_numbers: bool = True
    page_numbers_on_first_page: bool = True

    def to_render_options(self) -> RenderOptions:
        return RenderOptions(
            add_page_numbers=self.add_page_numbers,
            page_numbers_on_first_page=self.page_numbers_on_first_page,
        )


@dataclass
class ConversionResult:
    pdf: bytes
    filename: str
    page_count: int
    images_removed: int
    report: CleanReport
    warnings: List[str] = field(default_factory=list)

    @property
    def summary(self) -> dict:
        return {
            'filename': self.filename,
            'pages': self.page_count,
            'images_removed': self.images_removed,
            'comment_markers_removed': self.report.comment_markers_removed,
            'highlighting_removed': self.report.total_highlighting_removed,
            'warnings': self.warnings,
        }


def safe_filename(title: Optional[str], fallback: str = 'document') -> str:
    """A download name that is safe on every platform, without the extension."""
    stem = (title or '').strip()
    stem = stem.rsplit('/', 1)[-1].rsplit('\\', 1)[-1]
    if stem.lower().endswith('.docx'):
        stem = stem[:-5]
    stem = _UNSAFE_NAME.sub(' ', stem)
    stem = re.sub(r'\s+', ' ', stem).strip(' .')
    stem = stem[:80].strip()
    return stem or fallback


def convert_docx(data: bytes, options: Optional[ConversionOptions] = None,
                 title: Optional[str] = None) -> ConversionResult:
    """Convert .docx bytes into a print-ready PDF."""
    options = options or ConversionOptions()

    if not data:
        raise InvalidDocxError('Het bestand is leeg.')
    if len(data) > MAX_UPLOAD_BYTES:
        raise InvalidDocxError('Het bestand is groter dan de limiet van %d MB.'
                               % (MAX_UPLOAD_BYTES // (1024 * 1024)))

    package = Package(data)
    report = clean(package)
    rendered = render(package, options.to_render_options())
    result = render_pdf(rendered,
                        images_first_page_only=options.images_first_page_only)

    stem = safe_filename(title)
    return ConversionResult(
        pdf=result.data,
        filename='%s - printklaar.pdf' % stem,
        page_count=result.page_count,
        images_removed=result.images_removed,
        report=report,
        warnings=result.warnings,
    )


def convert_google_doc(url: str, options: Optional[ConversionOptions] = None,
                       access_token: Optional[str] = None) -> ConversionResult:
    """Fetch a Google document and convert it into a print-ready PDF."""
    document = gdocs.download(url, access_token=access_token)
    log.info('Google Docs %s opgehaald (%d bytes)', document.doc_id, len(document.data))
    return convert_docx(document.data, options=options,
                        title=document.title or document.doc_id[:12])


__all__ = [
    'ConversionOptions',
    'ConversionResult',
    'MAX_UPLOAD_BYTES',
    'convert_docx',
    'convert_google_doc',
    'safe_filename',
]
