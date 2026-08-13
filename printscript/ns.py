"""
OOXML namespaces and small XML helpers.

Everything in PrintScript works directly on the WordprocessingML XML tree via
lxml.  Keeping the namespace map and the attribute helpers in one place avoids
the string-literal soup that OOXML code usually turns into.
"""

from __future__ import annotations

from typing import Optional

from lxml import etree

NS = {
    'w':    'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
    'r':    'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    'a':    'http://schemas.openxmlformats.org/drawingml/2006/main',
    'pic':  'http://schemas.openxmlformats.org/drawingml/2006/picture',
    'wp':   'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
    'wps':  'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
    'mc':   'http://schemas.openxmlformats.org/markup-compatibility/2006',
    'v':    'urn:schemas-microsoft-com:vml',
    'w14':  'http://schemas.microsoft.com/office/word/2010/wordml',
    'rel':  'http://schemas.openxmlformats.org/package/2006/relationships',
    'ct':   'http://schemas.openxmlformats.org/package/2006/content-types',
    'xml':  'http://www.w3.org/XML/1998/namespace',
}


def qn(name: str) -> str:
    """'w:pPr' -> '{http://…/wordprocessingml/2006/main}pPr'."""
    prefix, _, local = name.partition(':')
    return '{%s}%s' % (NS[prefix], local)


def find(el, path: str):
    """el.find(path) with the PrintScript namespace map applied."""
    if el is None:
        return None
    return el.find(path, NS)


def findall(el, path: str):
    if el is None:
        return []
    return el.findall(path, NS)


def attr(el, name: str = 'w:val', default: Optional[str] = None) -> Optional[str]:
    """Read a namespaced attribute off an element (None-safe)."""
    if el is None:
        return default
    v = el.get(qn(name))
    return default if v is None else v


def toggle(el, default: bool = False) -> bool:
    """
    OOXML on/off semantics: ``<w:b/>`` means on, ``<w:b w:val="0"/>`` means off,
    a missing element means "inherit" (represented here by *default*).
    """
    if el is None:
        return default
    v = el.get(qn('w:val'))
    if v is None:
        return True
    return v not in ('0', 'false', 'off')


def to_int(value, default=None):
    try:
        return int(round(float(value)))
    except (TypeError, ValueError):
        return default


def to_float(value, default=None):
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


# ── Unit conversions ─────────────────────────────────────────────────────────
# Word stores lengths in twentieths of a point (twips), font sizes in half
# points, border widths in eighths of a point and drawing sizes in EMUs.

def twips_to_pt(value, default=None):
    v = to_float(value)
    return default if v is None else v / 20.0


def half_points_to_pt(value, default=None):
    v = to_float(value)
    return default if v is None else v / 2.0


def eighth_points_to_pt(value, default=None):
    v = to_float(value)
    return default if v is None else v / 8.0


def emu_to_pt(value, default=None):
    v = to_float(value)
    return default if v is None else v / 12700.0


def fmt_pt(value: float) -> str:
    """Format a pt value compactly: 12.0 -> '12pt', 12.25 -> '12.25pt'."""
    rounded = round(value, 2)
    if rounded == int(rounded):
        return '%dpt' % int(rounded)
    return ('%.2f' % rounded).rstrip('0').rstrip('.') + 'pt'


def parse_xml(data: bytes):
    """Parse an OOXML part.  Recovering parser: never fail on odd markup."""
    parser = etree.XMLParser(recover=True, huge_tree=True, resolve_entities=False)
    return etree.fromstring(data, parser)
