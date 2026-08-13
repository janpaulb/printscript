"""
In-memory OOXML package.

A .docx is a zip of XML parts glued together by relationship files.  This
module gives the rest of PrintScript a tiny, predictable view of that package:
read a part, parse a part, follow a relationship, drop a part.

Nothing here talks to the filesystem — a package is created from bytes and can
be turned back into bytes, which keeps the whole pipeline testable.
"""

from __future__ import annotations

import io
import posixpath
import zipfile
from dataclasses import dataclass
from typing import Dict, Optional

from lxml import etree

from .ns import NS, parse_xml, qn

CONTENT_TYPES = '[Content_Types].xml'

_OFFICE_DOCUMENT = (
    'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument'
)


class InvalidDocxError(ValueError):
    """Raised when the supplied bytes are not a readable .docx package."""


@dataclass(frozen=True)
class Relationship:
    id: str
    type: str
    target: str
    external: bool

    @property
    def kind(self) -> str:
        """Last path segment of the relationship type ('image', 'header', …)."""
        return self.type.rsplit('/', 1)[-1]


class Package:
    """A .docx package held in memory."""

    def __init__(self, data: bytes):
        try:
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                bad = zf.testzip()
                if bad is not None:
                    raise InvalidDocxError('Beschadigd onderdeel in het bestand: %s' % bad)
                self._parts: Dict[str, bytes] = {
                    info.filename: zf.read(info.filename)
                    for info in zf.infolist()
                    if not info.is_dir()
                }
        except zipfile.BadZipFile as exc:
            raise InvalidDocxError(
                'Dit is geen geldig .docx-bestand (geen zip-pakket).'
            ) from exc

        if CONTENT_TYPES not in self._parts:
            raise InvalidDocxError(
                'Dit is geen geldig .docx-bestand ([Content_Types].xml ontbreekt).'
            )

        self._trees: Dict[str, etree._Element] = {}
        self._rels_cache: Dict[str, Dict[str, Relationship]] = {}

        if self.main_part_name() is None:
            raise InvalidDocxError(
                'Dit .docx-bestand bevat geen hoofddocument (word/document.xml).'
            )

    # ── Parts ────────────────────────────────────────────────────────────────

    @property
    def part_names(self):
        return list(self._parts)

    def has_part(self, name: str) -> bool:
        return name in self._parts

    def blob(self, name: str) -> Optional[bytes]:
        return self._parts.get(name)

    def tree(self, name: str):
        """Parsed XML tree for a part, cached.  Mutations are kept."""
        if name in self._trees:
            return self._trees[name]
        blob = self._parts.get(name)
        if blob is None:
            return None
        tree = parse_xml(blob)
        if tree is None:
            return None
        self._trees[name] = tree
        return tree

    def drop_part(self, name: str) -> None:
        """Remove a part plus its content-type override and inbound relationships."""
        if name not in self._parts:
            return
        del self._parts[name]
        self._trees.pop(name, None)
        self._drop_content_type_override(name)
        self._drop_inbound_rels(name)

    # ── Relationships ────────────────────────────────────────────────────────

    @staticmethod
    def rels_name_for(part_name: str) -> str:
        directory, _, base = part_name.rpartition('/')
        return posixpath.join(directory, '_rels', base + '.rels') if directory else \
            posixpath.join('_rels', base + '.rels')

    def rels(self, part_name: str) -> Dict[str, Relationship]:
        """Relationships declared by *part_name*, keyed by r:id."""
        if part_name in self._rels_cache:
            return self._rels_cache[part_name]

        result: Dict[str, Relationship] = {}
        tree = self.tree(self.rels_name_for(part_name))
        if tree is not None:
            for node in tree.findall('rel:Relationship', NS):
                rid = node.get('Id')
                if not rid:
                    continue
                result[rid] = Relationship(
                    id=rid,
                    type=node.get('Type', ''),
                    target=node.get('Target', ''),
                    external=(node.get('TargetMode') == 'External'),
                )
        self._rels_cache[part_name] = result
        return result

    def related_part_name(self, part_name: str, rid: str) -> Optional[str]:
        """Resolve an r:id into an absolute part name, or None for external targets."""
        rel = self.rels(part_name).get(rid)
        if rel is None or rel.external:
            return None
        return self._resolve_target(part_name, rel.target)

    def related_blob(self, part_name: str, rid: str) -> Optional[bytes]:
        target = self.related_part_name(part_name, rid)
        return self.blob(target) if target else None

    @staticmethod
    def _resolve_target(source_part: str, target: str) -> str:
        if target.startswith('/'):
            return target.lstrip('/')
        base = posixpath.dirname(source_part)
        return posixpath.normpath(posixpath.join(base, target)).lstrip('/')

    def main_part_name(self) -> Optional[str]:
        """The main document part, found through the package relationships."""
        for rel in self.rels('').values():
            if rel.type == _OFFICE_DOCUMENT and not rel.external:
                name = self._resolve_target('', rel.target)
                if name in self._parts:
                    return name
        # Fall back to the conventional location.
        return 'word/document.xml' if 'word/document.xml' in self._parts else None

    # ── Serialisation ────────────────────────────────────────────────────────

    def to_bytes(self) -> bytes:
        """Re-zip the package, writing back any parts whose tree was mutated."""
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as zf:
            for name, blob in self._parts.items():
                tree = self._trees.get(name)
                if tree is not None:
                    blob = etree.tostring(
                        tree, xml_declaration=True, encoding='UTF-8', standalone=True
                    )
                zf.writestr(name, blob)
        return buffer.getvalue()

    # ── Internals ────────────────────────────────────────────────────────────

    def _drop_content_type_override(self, part_name: str) -> None:
        tree = self.tree(CONTENT_TYPES)
        if tree is None:
            return
        wanted = '/' + part_name
        for override in tree.findall('ct:Override', NS):
            if override.get('PartName') == wanted:
                tree.remove(override)
        self._parts[CONTENT_TYPES] = etree.tostring(
            tree, xml_declaration=True, encoding='UTF-8', standalone=True
        )

    def _drop_inbound_rels(self, part_name: str) -> None:
        for rels_part in [n for n in self._parts if n.endswith('.rels')]:
            tree = self.tree(rels_part)
            if tree is None:
                continue
            owner = _owner_of_rels(rels_part)
            changed = False
            for node in list(tree.findall('rel:Relationship', NS)):
                if node.get('TargetMode') == 'External':
                    continue
                if self._resolve_target(owner, node.get('Target', '')) == part_name:
                    tree.remove(node)
                    changed = True
            if changed:
                self._parts[rels_part] = etree.tostring(
                    tree, xml_declaration=True, encoding='UTF-8', standalone=True
                )
                self._rels_cache.pop(owner, None)


def _owner_of_rels(rels_part: str) -> str:
    """'word/_rels/document.xml.rels' -> 'word/document.xml'."""
    directory, _, base = rels_part.rpartition('/')
    parent = directory[: -len('/_rels')] if directory.endswith('_rels') else ''
    stem = base[: -len('.rels')]
    return posixpath.join(parent, stem) if parent else stem


def content_type_of(package: Package, part_name: str) -> Optional[str]:
    """Content type for a part, from an Override or the extension Default."""
    tree = package.tree(CONTENT_TYPES)
    if tree is None:
        return None
    wanted = '/' + part_name
    for override in tree.findall('ct:Override', NS):
        if override.get('PartName') == wanted:
            return override.get('ContentType')
    extension = part_name.rsplit('.', 1)[-1].lower() if '.' in part_name else ''
    for default in tree.findall('ct:Default', NS):
        if (default.get('Extension') or '').lower() == extension:
            return default.get('ContentType')
    return None


__all__ = [
    'CONTENT_TYPES',
    'InvalidDocxError',
    'Package',
    'Relationship',
    'content_type_of',
    'qn',
]
