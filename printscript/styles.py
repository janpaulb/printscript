"""
Style and numbering tables.

Word's formatting model is a cascade: document defaults → paragraph style
(through its basedOn chain) → numbering level → direct formatting.  These two
classes flatten the first parts of that cascade into ordered lists of property
elements, which the renderer then walks in order to build CSS.
"""

from __future__ import annotations

import re
from typing import Dict, List, Optional, Tuple

from .ns import NS, attr, find, findall, qn, to_int
from .package import Package

_HEADING_RE = re.compile(r'^heading\s*([1-9])$', re.IGNORECASE)
_MAX_CHAIN = 24  # guards against basedOn cycles in hand-edited documents


class StyleTable:
    """Flattened view of word/styles.xml."""

    def __init__(self, package: Package, part_name: Optional[str] = None):
        self.default_paragraph_style: Optional[str] = None
        self.default_character_style: Optional[str] = None
        self.doc_default_ppr = None
        self.doc_default_rpr = None
        self._styles: Dict[str, dict] = {}

        root = package.tree(part_name) if part_name else None
        if root is None:
            return

        defaults = find(root, 'w:docDefaults')
        if defaults is not None:
            self.doc_default_rpr = find(defaults, 'w:rPrDefault/w:rPr')
            self.doc_default_ppr = find(defaults, 'w:pPrDefault/w:pPr')

        for style in findall(root, 'w:style'):
            style_id = attr(style, 'w:styleId')
            if not style_id:
                continue
            style_type = attr(style, 'w:type', 'paragraph')
            name = attr(find(style, 'w:name'), 'w:val', '') or ''
            entry = {
                'id': style_id,
                'type': style_type,
                'name': name,
                'based_on': attr(find(style, 'w:basedOn'), 'w:val'),
                'ppr': find(style, 'w:pPr'),
                'rpr': find(style, 'w:rPr'),
                'tblpr': find(style, 'w:tblPr'),
                'num_id': attr(find(style, 'w:pPr/w:numPr/w:numId'), 'w:val'),
                'num_level': attr(find(style, 'w:pPr/w:numPr/w:ilvl'), 'w:val'),
                'heading_level': _heading_level(style_id, name),
            }
            self._styles[style_id] = entry
            if attr(style, 'w:default') in ('1', 'true', 'on'):
                if style_type == 'paragraph' and self.default_paragraph_style is None:
                    self.default_paragraph_style = style_id
                elif style_type == 'character' and self.default_character_style is None:
                    self.default_character_style = style_id

    # ── Lookup ───────────────────────────────────────────────────────────────

    def __contains__(self, style_id: str) -> bool:
        return style_id in self._styles

    def get(self, style_id: Optional[str]) -> Optional[dict]:
        return self._styles.get(style_id) if style_id else None

    @property
    def ids(self) -> List[str]:
        return list(self._styles)

    def chain(self, style_id: Optional[str]) -> List[dict]:
        """Style entries from the root of the basedOn chain down to *style_id*."""
        entries: List[dict] = []
        seen = set()
        current = self._styles.get(style_id) if style_id else None
        while current is not None and current['id'] not in seen and len(entries) < _MAX_CHAIN:
            seen.add(current['id'])
            entries.append(current)
            current = self._styles.get(current['based_on'])
        entries.reverse()
        return entries

    def paragraph_properties(self, style_id: Optional[str]) -> List:
        """pPr elements for a paragraph style, base first."""
        return [e['ppr'] for e in self.chain(style_id) if e['ppr'] is not None]

    def run_properties(self, style_id: Optional[str]) -> List:
        """rPr elements for a style, base first."""
        return [e['rpr'] for e in self.chain(style_id) if e['rpr'] is not None]

    def heading_level(self, style_id: Optional[str]) -> Optional[int]:
        for entry in reversed(self.chain(style_id)):
            if entry['heading_level']:
                return entry['heading_level']
        return None

    def numbering_of(self, style_id: Optional[str]) -> Tuple[Optional[str], int]:
        """(numId, ilvl) declared by a paragraph style, walking the basedOn chain."""
        num_id = None
        level = 0
        for entry in self.chain(style_id):
            if entry['num_id'] is not None:
                num_id = entry['num_id']
                level = to_int(entry['num_level'], 0) or 0
        return num_id, level


def _heading_level(style_id: str, name: str) -> Optional[int]:
    for candidate in (name, style_id):
        if not candidate:
            continue
        match = _HEADING_RE.match(candidate.strip().replace('Heading', 'heading '))
        if match:
            return int(match.group(1))
    match = re.match(r'^Heading([1-9])$', style_id or '')
    if match:
        return int(match.group(1))
    return None


class Numbering:
    """Flattened view of word/numbering.xml."""

    def __init__(self, package: Package, part_name: Optional[str] = None,
                 styles: Optional[StyleTable] = None):
        self._abstract: Dict[str, Dict[int, object]] = {}
        self._num_to_abstract: Dict[str, str] = {}
        self._overrides: Dict[Tuple[str, int], object] = {}
        self._style_links: Dict[str, str] = {}
        self._styles = styles

        root = package.tree(part_name) if part_name else None
        if root is None:
            return

        for abstract in findall(root, 'w:abstractNum'):
            abstract_id = attr(abstract, 'w:abstractNumId')
            if abstract_id is None:
                continue
            levels = {}
            for lvl in findall(abstract, 'w:lvl'):
                ilvl = to_int(attr(lvl, 'w:ilvl'), 0) or 0
                levels[ilvl] = lvl
            self._abstract[abstract_id] = levels
            link = attr(find(abstract, 'w:numStyleLink'), 'w:val')
            if link:
                self._style_links[abstract_id] = link

        for num in findall(root, 'w:num'):
            num_id = attr(num, 'w:numId')
            abstract_id = attr(find(num, 'w:abstractNumId'), 'w:val')
            if num_id is None or abstract_id is None:
                continue
            self._num_to_abstract[num_id] = abstract_id
            for override in findall(num, 'w:lvlOverride'):
                ilvl = to_int(attr(override, 'w:ilvl'), 0) or 0
                lvl = find(override, 'w:lvl')
                if lvl is not None:
                    self._overrides[(num_id, ilvl)] = lvl

    def __bool__(self) -> bool:
        return bool(self._num_to_abstract)

    def level(self, num_id: Optional[str], ilvl: int):
        """The w:lvl element for a (numId, ilvl) pair, or None."""
        if not num_id or num_id == '0':
            return None
        override = self._overrides.get((num_id, ilvl))
        if override is not None:
            return override

        abstract_id = self._num_to_abstract.get(num_id)
        seen = set()
        while abstract_id is not None and abstract_id not in seen:
            seen.add(abstract_id)
            link = self._style_links.get(abstract_id)
            if link and self._styles is not None:
                linked_num, _ = self._styles.numbering_of(link)
                next_abstract = self._num_to_abstract.get(linked_num) if linked_num else None
                if next_abstract and next_abstract not in seen:
                    abstract_id = next_abstract
                    continue
            levels = self._abstract.get(abstract_id) or {}
            return levels.get(ilvl)
        return None

    def format_of(self, num_id: Optional[str], ilvl: int) -> str:
        lvl = self.level(num_id, ilvl)
        return attr(find(lvl, 'w:numFmt'), 'w:val', 'decimal') or 'decimal'

    def text_template(self, num_id: Optional[str], ilvl: int) -> str:
        lvl = self.level(num_id, ilvl)
        return attr(find(lvl, 'w:lvlText'), 'w:val', '') or ''

    def start_at(self, num_id: Optional[str], ilvl: int) -> int:
        lvl = self.level(num_id, ilvl)
        return to_int(attr(find(lvl, 'w:start'), 'w:val'), 1) or 1

    def restart_after(self, num_id: Optional[str], ilvl: int) -> Optional[int]:
        """w:lvlRestart — which level must change before this one restarts."""
        lvl = self.level(num_id, ilvl)
        value = attr(find(lvl, 'w:lvlRestart'), 'w:val')
        return to_int(value) if value is not None else None

    def indent(self, num_id: Optional[str], ilvl: int):
        return find(self.level(num_id, ilvl), 'w:pPr/w:ind')

    def run_properties(self, num_id: Optional[str], ilvl: int):
        return find(self.level(num_id, ilvl), 'w:rPr')


__all__ = ['Numbering', 'StyleTable', 'NS', 'qn']
