"""
HTML → PDF, and the page-1 image rule.

"Remove every image after page 1" is a statement about the *printed* document,
not about the markup: which page an image lands on only becomes knowable once
the document has been laid out.  So PrintScript lays the document out once,
asks WeasyPrint which page each image ended up on, drops the ones that are not
on page 1, and lays it out again.

That second pass is safe: removing content can only ever pull later content
*towards* the front, so an image that was on page 1 stays on page 1, and no new
image can appear there.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Dict, List, Optional

import weasyprint
from weasyprint.text.fonts import FontConfiguration

from .docxhtml import RenderResult, has_visible_content

log = logging.getLogger(__name__)

# WeasyPrint chatters about every unreachable resource; we surface the ones we
# care about ourselves.
logging.getLogger('weasyprint').setLevel(logging.ERROR)
logging.getLogger('fontTools').setLevel(logging.ERROR)

_PARAGRAPH_TAGS = ('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6')


@dataclass
class PdfResult:
    data: bytes
    page_count: int
    images_removed: int = 0
    warnings: List[str] = field(default_factory=list)


def _build_offline_fetcher():
    """
    Every image is inlined as a data: URI before we get here, so a request for
    anything else means the document is pointing at the network.  Refuse it —
    a converted document must never make the server fetch a remote URL.
    """
    try:
        from weasyprint.urls import URLFetcher
        return URLFetcher(allowed_protocols=('data',))
    except ImportError:                       # WeasyPrint < 69
        def fetcher(url: str, *_args, **_kwargs):
            if url.startswith('data:'):
                return weasyprint.default_url_fetcher(url)
            raise ValueError('Externe bronnen worden niet opgehaald: %s' % url[:80])
        return fetcher


_OFFLINE_FETCHER = _build_offline_fetcher()


def _page_index_of_anchors(document) -> Dict[str, int]:
    """Map every anchor name to the index of the page it was laid out on."""
    located: Dict[str, int] = {}
    for index, page in enumerate(document.pages):
        for name in getattr(page, 'anchors', {}) or {}:
            located.setdefault(name, index)
    return located


def _drop_element(element) -> None:
    """Remove an element and any wrappers it leaves empty behind."""
    parent = element.getparent()
    if parent is None:
        return
    tail = element.tail or ''
    parent.remove(element)
    if tail:
        _append_tail(parent, tail)

    # Walk up through inline wrappers (span/a) that are now empty, then drop
    # the whole paragraph if nothing visible is left in it.
    node = parent
    while node is not None and node.tag in ('span', 'a'):
        if len(node) or (node.text or '').strip():
            break
        upper = node.getparent()
        if upper is None:
            break
        node.getparent().remove(node)
        node = upper
    while node is not None and node.tag in _PARAGRAPH_TAGS:
        if has_visible_content(node):
            break
        upper = node.getparent()
        if upper is None:
            break
        upper.remove(node)
        node = None


def _append_tail(element, text: str) -> None:
    if len(element):
        element[-1].tail = (element[-1].tail or '') + text
    else:
        element.text = (element.text or '') + text


def render_pdf(result: RenderResult, images_first_page_only: bool = True,
               base_url: Optional[str] = None) -> PdfResult:
    """Lay the rendered HTML out and return the finished PDF."""
    warnings = list(result.warnings)
    font_config = FontConfiguration()

    def build(html_string: str):
        return weasyprint.HTML(
            string=html_string,
            base_url=base_url,
            url_fetcher=_OFFLINE_FETCHER,
        ).render(font_config=font_config)

    document = build(result.to_html())
    images_removed = 0

    if images_first_page_only and result.body_image_ids:
        located = _page_index_of_anchors(document)
        doomed = [image_id for image_id in result.body_image_ids
                  if located.get(image_id, 0) > 0]
        if doomed:
            index = {element.get('id'): element
                     for element in result.tree.iter('img')
                     if element.get('id')}
            for image_id in doomed:
                element = index.get(image_id)
                if element is not None:
                    _drop_element(element)
                    images_removed += 1
            document = build(result.to_html())

    data = document.write_pdf()
    return PdfResult(
        data=data,
        page_count=len(document.pages),
        images_removed=images_removed,
        warnings=warnings,
    )


__all__ = ['PdfResult', 'render_pdf']
